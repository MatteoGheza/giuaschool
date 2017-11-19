<?php
/**
 * giua@school
 *
 * Copyright (c) 2017 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017
 */


namespace AppBundle\Util;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Form\Form;
use AppBundle\Entity\Docente;
use AppBundle\Entity\Classe;
use AppBundle\Entity\Sede;
use AppBundle\Entity\Materia;
use AppBundle\Entity\Lezione;
use AppBundle\Entity\Cattedra;
use AppBundle\Entity\Annotazione;
use AppBundle\Entity\Nota;
use AppBundle\Entity\Firma;
use AppBundle\Entity\FirmaSostegno;
use AppBundle\Entity\Alunno;
use AppBundle\Entity\AssenzaLezione;
use AppBundle\Entity\OsservazioneClasse;
use AppBundle\Form\Appello;
use AppBundle\Form\VotoClasse;


/**
 * RegistroUtil - classe di utilità per la gestione del registro di classe
 */
class RegistroUtil {


  //==================== ATTRIBUTI DELLA CLASSE  ====================

  /**
   * @var RouterInterface $router Gestore delle URL
   */
  private $router;

  /**
   * @var EntityManagerInterface $em Gestore delle entità
   */
  private $em;

  /**
   * @var TranslatorInterface $trans Gestore delle traduzioni
   */
  private $trans;

  /**
   * @var SessionInterface $session Gestore delle sessioni
   */
  private $session;


  //==================== METODI DELLA CLASSE ====================

  /**
   * Construttore
   *
   * @param RouterInterface $router Gestore delle URL
   * @param EntityManagerInterface $em Gestore delle entità
   * @param TranslatorInterface $trans Gestore delle traduzioni
   * @param SessionInterface $session Gestore delle sessioni
   */
  public function __construct(RouterInterface $router, EntityManagerInterface $em, TranslatorInterface $trans,
                               SessionInterface $session) {
    $this->router = $router;
    $this->em = $em;
    $this->trans = $trans;
    $this->session = $session;
  }

  /**
   * Controlla se la data è festiva per la sede indicata.
   * Se è festiva restituisce la descrizione della festività.
   * Non sono considerate le assemblee di istituto (non sono giorni festivi).
   *
   * @param \DateTime $data Data da controllare
   * @param Sede $sede Sede da controllare (se nullo, festività di entrambe le sedi)
   *
   * @return string|null Stringa di errore o null se tutto ok
   */
  public function controlloData(\DateTime $data, Sede $sede=null) {
    // query
    $lista = $this->em->getRepository('AppBundle:Festivita')->createQueryBuilder('f')
      ->where('(f.sede IS NULL OR f.sede=:sede) AND f.tipo=:tipo AND f.data=:data')
      ->setParameters(['sede' => $sede, 'tipo' => 'F', 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getResult();
    if (count($lista) > 0) {
      // giorno festivo
      return $lista[0]->getDescrizione();
    }
    // controllo inizio anno scolastico
    $inizio = $this->em->getRepository('AppBundle:Configurazione')->findOneByParametro('anno_inizio');
    if ($inizio && $data->format('Y-m-d') < $inizio->getValore()){
      // prima inizio anno
      return $this->trans->trans('exception.prima_inizio_anno');
    }
    // controllo fine anno scolastico
    $fine = $this->em->getRepository('AppBundle:Configurazione')->findOneByParametro('anno_fine');
    if ($fine && $data->format('Y-m-d') > $fine->getValore()){
      // dopo fine anno
      return $this->trans->trans('exception.dopo_fine_anno');
    }
    // controllo riposo settimanale (domenica e altri)
    $weekdays = $this->em->getRepository('AppBundle:Configurazione')->findOneByParametro('giorni_festivi');
    if ($weekdays && in_array($data->format('w'), explode(',', $weekdays->getValore()))) {
      // domenica
      return $this->trans->trans('exception.giorno_riposo_settimanale');
    }
    // giorno non festivo
    return null;
  }

  /**
   * Restituisce la lista delle date dei giorni festivi per la sede.
   * Non sono considerate le assemblee di istituto (non sono giorni festivi).
   * Sono esclusi i giorni che precedono o seguono il periodo dell'anno scolastico.
   * Non sono indicati i riposi settimanali (domenica ed eventuali altri).
   *
   * @param Sede $sede Sede da controllare (se nullo, festività di entrambe le sedi)
   *
   * @return array Lista di giorni festivi come stringhe di date
   */
  public function listaFestivi(Sede $sede=null) {
    // query
    $lista = $this->em->getRepository('AppBundle:Festivita')->createQueryBuilder('f')
      ->where('(f.sede IS NULL OR f.sede=:sede) AND f.tipo=:tipo')
      ->setParameters(['sede' => $sede, 'tipo' => 'F'])
      ->orderBy('f.data', 'ASC')
      ->getQuery()
      ->getResult();
    // crea lista date
    $lista_date = '';
    foreach ($lista as $f) {
      $lista_date .= ',"'.$f->getData()->format('Y-m-d').'"';
    }
    return '['.substr($lista_date, 1).']';
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alle lezioni.
   *
   * @param string $azione Azione da controllare
   * @param \DateTime $data Data della lezione
   * @param int $ora Ora della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Materia $materia Materia della lezione
   * @param Lezione $lezione Lezione esistente
   * @param array $firme Lista di firme di lezione, con id del docente
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneLezione($azione, \DateTime $data, $ora, Docente $docente, Classe $classe, Materia $materia,
                                 Lezione $lezione=null, $firme=null) {
    if ($azione == 'add') {
      // azione di creazione
      $oggi = new \DateTime();
      if ($data->format('Y-m-d') <= $oggi->format('Y-m-d')) {
        // data non nel futuro
        if (!$lezione) {
          // nuova lezione
          $altra_lezione = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
            ->join('AppBundle:Firma', 'f', 'WHERE', 'l.id=f.lezione')
            ->where('l.data=:data AND l.ora=:ora AND f.docente=:docente')
            ->setParameters(['data' => $data->format('Y-m-d'), 'ora' => $ora, 'docente' => $docente])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
          if (!$altra_lezione) {
            // nessun altra lezione in sovrapposizione
            return true;
          }
        }
      }
    } elseif ($azione == 'edit') {
      // azione di modifica
      if ($lezione && $firme && count($firme) > 0) {
        // esiste lezione e firme
        if ($materia->getId() == $lezione->getMateria()->getId()) {
          // stessa materia di lezione esistente: ok
          return true;
        }
        if ($materia->getTipo() == 'S' || $lezione->getMateria()->getTipo() == 'S') {
          // materia di sostegno o lezione di sostegno: ok
          return true;
        }
      }
    } elseif ($azione == 'delete') {
      // azione di cancellazione
      if ($lezione && $firme && count($firme) > 0) {
        // esiste lezione e firme
        if (in_array($docente->getId(), $firme)) {
          // docente ha firmato lezione
          $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.lezione=:lezione AND v.docente=:docente')
            ->setParameters(['lezione' => $lezione, 'docente' => $docente])
            ->getQuery()
            ->getSingleScalarResult();
          if ($voti == 0) {
            // nessun voto associato alla lezione
            return true;
          } else {
            // sono presenti voti
            $num_lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
              ->select('COUNT(l.id)')
              ->join('AppBundle:Firma', 'f', 'WHERE', 'l.id=f.lezione')
              ->where('l.data=:data AND l.classe=:classe AND l.materia=:materia AND f.docente=:docente')
              ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe, 'materia' => $materia,
                'docente' => $docente])
              ->getQuery()
              ->getSingleScalarResult();
            if ($num_lezioni > 1) {
              // sono presenti più ore di lezione
              return true;
            }
          }
        }
      }
    }
    // non consentito
    return false;
  }

  /**
   * Restituisce la lista delle ore consecutive che si possono aggiungere come lezione
   *
   * @param \DateTime $data Data della lezione
   * @param int $ora Ora di inzio della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Materia $materia Materia della lezione
   *
   * @return array Orario di inizio e lista di ore consecutive che si possono aggiungere
   */
  public function lezioneOreConsecutive(\DateTime $data, $ora, Docente $docente, Classe $classe, Materia $materia) {
    $dati = array();
    // legge ora di inzio
    $scansione_orario = $this->em->getRepository('AppBundle:ScansioneOraria')->createQueryBuilder('s')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno AND s.ora>=:ora')
      ->orderBy('s.ora', 'ASC')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $classe->getSede(),
        'giorno' => $data->format('w'), 'ora' => $ora])
      ->getQuery()
      ->getResult();
    // lista ore
    foreach ($scansione_orario as $k=>$s) {
      if ($k == 0) {
        // ora iniziale
        $dati['inizio'] = $s->getInizio()->format('H:i');
        $durata = $s->getDurata() / 60.0;
        $durata_str = ($durata < 1 ? 'mezz\'ora' : '1 ora');
        $key = $s->getFine()->format('H:i')." ($durata_str)";
        $dati['fine'][$key] = $s->getOra();
      } else {
        // ore successive
        $lezione = $this->em->getRepository('AppBundle:Lezione')->findOneBy(['classe' => $classe,
          'data' => $data, 'ora' => $s->getOra()]);
        if (!$this->azioneLezione('add', $data, $s->getOra(), $docente, $classe, $materia, $lezione)) {
          // operazione non ammessa: esce
          break;
        }
        $durata += $s->getDurata() / 60.0;
        $durata_str = intval($durata).($durata >= 2 ? ' ore' : ' ora').($durata > intval($durata) ? ' e mezza' : '');
        $key = $s->getFine()->format('H:i')." ($durata_str)";
        $dati['fine'][$key] = $s->getOra();
      }
    }
    return $dati;
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alle annotazioni.
   *
   * @param string $azione Azione da controllare
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione (nullo se qualsiasi)
   * @param Annotazione $annotazione Annotazione sul registro
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneAnnotazione($azione, \DateTime $data, Docente $docente, Classe $classe=null,
                                     Annotazione $annotazione=null) {
    if ($azione == 'add') {
      // azione di creazione
      if (!$annotazione) {
        // ok (anche in data futura)
        return true;
      }
    } elseif ($azione == 'edit') {
      // azione di modifica
      if ($annotazione) {
        // esiste annotazione
        if ($docente->getId() == $annotazione->getDocente()->getId()) {
          // stesso docente: ok
          return true;
        }
        if (in_array('ROLE_STAFF', $annotazione->getDocente()->getRoles()) && in_array('ROLE_STAFF', $docente->getRoles())) {
          // docente è dello staff come anche chi ha scritto annotazione: ok
          return true;
        }
      }
    } elseif ($azione == 'delete') {
      // azione di cancellazione
      if ($annotazione) {
        // esiste annotazione
        if ($docente->getId() == $annotazione->getDocente()->getId()) {
          // stesso docente: ok
          return true;
        }
        if (in_array('ROLE_STAFF', $annotazione->getDocente()->getRoles()) && in_array('ROLE_STAFF', $docente->getRoles())) {
          // docente è dello staff come anche chi ha scritto annotazione: ok
          return true;
        }
      }
    }
    // non consentito
    return false;
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alle note disciplinari.
   *
   * @param string $azione Azione da controllare
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Nota $nota Nota disciplinare
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneNota($azione, \DateTime $data, Docente $docente, Classe $classe, Nota $nota=null) {
    if ($azione == 'add') {
      // azione di creazione
      $oggi = new \DateTime();
      if ($data->format('Y-m-d') <= $oggi->format('Y-m-d')) {
        // data non nel futuro
        if (!$nota) {
          // ok
          return true;
        }
      }
    } elseif ($azione == 'edit') {
      // azione di modifica
      if ($nota) {
        // esiste nota
        if ($docente->getId() == $nota->getDocente()->getId()) {
          // stesso docente: ok
          return true;
        }
        if (in_array('ROLE_STAFF', $docente->getRoles())) {
          // docente è dello staff: ok
          return true;
        }
      }
    } elseif ($azione == 'delete') {
      // azione di cancellazione
      if ($nota) {
        // esiste nota
        if ($docente->getId() == $nota->getDocente()->getId() && !$nota->getDocenteProvvedimento()) {
          // stesso docente di nota e nessun provvedimento: ok
          return true;
        }
        if ($docente->getId() == $nota->getDocente()->getId() && in_array('ROLE_STAFF', $docente->getRoles())) {
          // stesso docente di nota e fa parte di staff: ok
          return true;
        }
      }
    }
    // non consentito
    return false;
  }

  /**
   * Restituisce i dati del registro per la classe e l'intervallo di date indicato.
   *
   * @param \DateTime $data_inizio Data iniziale del registro
   * @param \DateTime $data_fine Data finale del registro
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Cattedra|null $cattedra Cattedra del docente (se nulla è supplenza)
   *
   * @return array Dati restituiti come array associativo
   */
  public function tabellaFirmeVista(\DateTime $data_inizio, \DateTime $data_fine, Docente $docente, Classe $classe,
                                     Cattedra $cattedra=null) {
    // legge materia
    if ($cattedra) {
      // lezioni di una cattedra esistente
      $materia = $cattedra->getMateria();
    } else {
      // supplenza
      $materia = $this->em->getRepository('AppBundle:Materia')->findOneByTipo('U');
      if (!$materia) {
        // errore: dati inconsistenti
        throw $this->createNotFoundException('exception.invalid_params');
      }
    }
    // ciclo per intervallo di date
    $dati = array();
    for ($data = clone $data_inizio; $data <= $data_fine; $data->modify('+1 day')) {
      $data_str = $data->format('Y-m-d');
      $dati[$data_str]['data'] = clone $data;
      $errore = $this->controlloData($data, $classe->getSede());
      if ($errore) {
        // festivo
        $dati[$data_str]['errore'] = $errore;
        continue;
      }
      // non festivo, legge orario
      $scansioneoraria = $this->orarioInData($data, $classe->getSede());
      // predispone dati lezioni come array associativo
      $dati_lezioni = array();
      foreach ($scansioneoraria as $s) {
        $ora = $s['ora'];
        $dati_lezioni[$ora]['inizio'] = substr($s['inizio'], 0, 5);
        $dati_lezioni[$ora]['fine'] = substr($s['fine'], 0, 5);
        // legge lezione
        $lezione = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
          ->where('l.data=:data AND l.classe=:classe AND l.ora=:ora')
          ->setParameters(['data' => $data_str, 'classe' => $classe, 'ora' => $ora])
          ->getQuery()
          ->getOneOrNullResult();
        if ($lezione) {
          // esiste lezione
          $dati_lezioni[$ora]['materia'] = $lezione->getMateria()->getNomeBreve();
          $dati_lezioni[$ora]['argomenti'] = trim($lezione->getArgomento().' '.$lezione->getAttivita());
          // legge firme
          $firme = $this->em->getRepository('AppBundle:Firma')->createQueryBuilder('f')
            ->join('f.docente', 'd')
            ->where('f.lezione=:lezione')
            ->orderBy('d.cognome,d.nome', 'ASC')
            ->setParameters(['lezione' => $lezione])
            ->getQuery()
            ->getResult();
          // docenti
          $docenti = array();
          foreach ($firme as $f) {
            $dati_lezioni[$ora]['docenti'][] = $f->getDocente()->getNome().' '.$f->getDocente()->getCognome();
            $docenti[] = $f->getDocente()->getId();
            if ($f instanceOf FirmaSostegno) {
              $dati_lezioni[$ora]['sostegno']['argomento'][] = trim($f->getArgomento().' '.$f->getAttivita());
              $dati_lezioni[$ora]['sostegno']['docente'][] = $f->getDocente()->getNome().' '.$f->getDocente()->getCognome();
              $dati_lezioni[$ora]['sostegno']['alunno'][] = $f->getAlunno()->getCognome().' '.$f->getAlunno()->getNome();
            }
          }
        } else {
          // nessuna lezione esistente
          $dati_lezioni[$ora]['materia'] = '';
          $dati_lezioni[$ora]['argomenti'] = '';
          $dati_lezioni[$ora]['docenti'] = '';
          $docenti = array();
        }
        // azioni
        if ($this->azioneLezione('add', $data, $ora, $docente, $classe, $materia, $lezione, $docenti)) {
          // pulsante add
          $dati_lezioni[$ora]['add'] = $this->router->generate('lezioni_registro_add', array(
            'cattedra' => ($cattedra ? $cattedra->getId() : 0),
            'classe' => $classe->getId(), 'data' =>$data->format('Y-m-d'), 'ora' => $ora));
        }
        if ($this->azioneLezione('edit', $data, $ora, $docente, $classe, $materia, $lezione, $docenti)) {
          // pulsante edit
          $dati_lezioni[$ora]['edit'] = $this->router->generate('lezioni_registro_edit', array(
            'cattedra' => ($cattedra ? $cattedra->getId() : 0),
            'classe' => $classe->getId(), 'data' =>$data->format('Y-m-d'), 'ora' => $ora));
        }
        if ($this->azioneLezione('delete', $data, $ora, $docente, $classe, $materia, $lezione, $docenti)) {
          // pulsante delete
          $dati_lezioni[$ora]['delete'] = $this->router->generate('lezioni_registro_delete', array(
            'classe' => $classe->getId(), 'data' =>$data->format('Y-m-d'), 'ora' => $ora));
        }
      }
      // memorizza lezioni del giorno
      $dati[$data_str]['lezioni'] = $dati_lezioni;
    }
    // legge annotazioni
    $annotazioni = $this->em->getRepository('AppBundle:Annotazione')->createQueryBuilder('a')
      ->join('a.docente', 'd')
      ->where('a.data BETWEEN :data_inizio AND :data_fine AND a.classe=:classe')
      ->orderBy('a.data', 'ASC')
      ->addOrderBy('a.modificato', 'DESC')
      ->setParameters(['data_inizio' => $data_inizio->format('Y-m-d'), 'data_fine' => $data_fine->format('Y-m-d'),
        'classe' => $classe])
      ->getQuery()
      ->getResult();
    // predispone dati per la visualizzazione
    $data_annotazione = null;
    $data_annotazione_prec = null;
    $lista = array();
    foreach ($annotazioni as $a) {
      $data_annotazione = $a->getData();
      if ($data_annotazione != $data_annotazione_prec && $data_annotazione_prec) {
        // conserva in vettore associativo
        $dati[$data_annotazione_prec->format('Y-m-d')]['annotazioni']['lista'] = $lista;
        $lista = array();
        // azione add
        if ($this->azioneAnnotazione('add', $data_annotazione_prec, $docente, $classe)) {
          // pulsante add
          $dati[$data_annotazione_prec->format('Y-m-d')]['annotazioni']['add'] =
            $this->router->generate('lezioni_registro_annotazione_edit', array('classe' => $classe->getId(),
            'data' => $data_annotazione_prec->format('Y-m-d')));
        }
      }
      $ann = array();
      $ann['id'] = $a->getId();
      $ann['testo'] = $a->getTesto();
      $ann['visibile'] = $a->getVisibile();
      $ann['docente'] = $a->getDocente()->getNome().' '.$a->getDocente()->getCognome();
      // controlla azioni
      if ($this->azioneAnnotazione('edit', $a->getData(), $docente, $classe, $a)) {
        // pulsante edit
        $ann['edit'] = $this->router->generate('lezioni_registro_annotazione_edit', array(
          'classe' => $classe->getId(), 'data' =>$a->getData()->format('Y-m-d'), 'id' => $a->getId()));
      }
      if ($this->azioneAnnotazione('delete', $a->getData(), $docente, $classe, $a)) {
        // pulsante delete
        $ann['delete'] = $this->router->generate('lezioni_registro_annotazione_delete', array(
          'id' => $a->getId()));
      }
      // raggruppa annotazioni per data
      $lista[] = $ann;
      $data_annotazione_prec = $data_annotazione;
    }
    if (count($annotazioni) > 0) {
      // conserva in vettore associativo
      $dati[$data_annotazione_prec->format('Y-m-d')]['annotazioni']['lista'] = $lista;
      // azione add
      if ($this->azioneAnnotazione('add', $data_annotazione_prec, $docente, $classe)) {
        // pulsante add
        $dati[$data_annotazione_prec->format('Y-m-d')]['annotazioni']['add'] =
          $this->router->generate('lezioni_registro_annotazione_edit', array('classe' => $classe->getId(),
          'data' => $data_annotazione_prec->format('Y-m-d')));
      }
    }
    // aggiunge info per date senza annotazioni
    for ($data = clone $data_inizio; $data <= $data_fine; $data->modify('+1 day')) {
      $data_str = $data->format('Y-m-d');
      if (!isset($dati[$data_str]['annotazioni'])) {
        $dati[$data_str]['annotazioni']['lista'] = array();
        // azione add
        if ($this->azioneAnnotazione('add', $data, $docente, $classe)) {
          // pulsante add
          $dati[$data_str]['annotazioni']['add'] = $this->router->generate('lezioni_registro_annotazione_edit', array(
            'classe' => $classe->getId(), 'data' => $data_str));
        }
      }
    }
    // legge note
    $note = $this->em->getRepository('AppBundle:Nota')->createQueryBuilder('n')
      ->join('n.docente', 'd')
      ->leftJoin('n.docenteProvvedimento', 'dp')
      ->where('n.data BETWEEN :data_inizio AND :data_fine AND n.classe=:classe')
      ->orderBy('n.data', 'ASC')
      ->addOrderBy('n.modificato', 'DESC')
      ->setParameters(['data_inizio' => $data_inizio->format('Y-m-d'), 'data_fine' => $data_fine->format('Y-m-d'),
        'classe' => $classe])
      ->getQuery()
      ->getResult();
    // predispone dati per la visualizzazione
    $data_nota = null;
    $data_nota_prec = null;
    $lista = array();
    foreach ($note as $n) {
      $data_nota = $n->getData();
      if ($data_nota != $data_nota_prec && $data_nota_prec) {
        // conserva in vettore associativo
        $dati[$data_nota_prec->format('Y-m-d')]['note']['lista'] = $lista;
        $lista = array();
        // azione add
        if ($this->azioneNota('add', $data_nota_prec, $docente, $classe)) {
          // pulsante add
          $dati[$data_nota_prec->format('Y-m-d')]['note']['add'] = $this->router->generate('lezioni_registro_nota_edit', array(
            'classe' => $classe->getId(), 'data' =>$data_nota_prec->format('Y-m-d')));
        }
      }
      $nt = array();
      $nt['id'] = $n->getId();
      $nt['tipo'] = $n->getTipo();
      $nt['testo'] = $n->getTesto();
      $nt['provvedimento'] = $n->getProvvedimento();
      $nt['docente'] = $n->getDocente()->getNome().' '.$n->getDocente()->getCognome();
      $nt['docente_provvedimento'] = ($n->getDocenteProvvedimento() ?
        $n->getDocenteProvvedimento()->getNome().' '.$n->getDocenteProvvedimento()->getCognome() : null);
      if ($n->getTipo() == 'I') {
        $alunni = '';
        $alunni_id = '';
        foreach ($n->getAlunni() as $alu) {
          $alunni .= ', '.$alu->getCognome().' '.$alu->getNome();
          $alunni_id .= ','.$alu->getId();
        }
        $alunni = substr($alunni, 2);
        $alunni_id = substr($alunni_id, 1);
        $nt['alunni'] = $alunni;
        $nt['alunni_id'] = $alunni_id;
      }
      // controlla azioni
      if ($this->azioneNota('edit', $n->getData(), $docente, $classe, $n)) {
        // pulsante edit
        $nt['edit'] = $this->router->generate('lezioni_registro_nota_edit', array(
          'classe' => $classe->getId(), 'data' => $n->getData()->format('Y-m-d'), 'id' => $n->getId()));
      }
      if ($this->azioneNota('delete', $n->getData(), $docente, $classe, $n)) {
        // pulsante delete
        $nt['delete'] = $this->router->generate('lezioni_registro_nota_delete', array(
          'id' => $n->getId()));
      }
      // raggruppa note per data
      $lista[] = $nt;
      $data_nota_prec = $data_nota;
    }
    if (count($note) > 0) {
      // conserva in vettore associativo
      $dati[$data_nota_prec->format('Y-m-d')]['note']['lista'] = $lista;
      // azione add
      if ($this->azioneNota('add', $data_nota_prec, $docente, $classe)) {
        // pulsante add
        $dati[$data_nota_prec->format('Y-m-d')]['note']['add'] = $this->router->generate('lezioni_registro_nota_edit', array(
          'classe' => $classe->getId(), 'data' =>$data_nota_prec->format('Y-m-d')));
      }
    }
    // aggiunge info per date senza note
    for ($data = clone $data_inizio; $data <= $data_fine; $data->modify('+1 day')) {
      $data_str = $data->format('Y-m-d');
      if (!isset($dati[$data_str]['note'])) {
        $dati[$data_str]['note']['lista'] = array();
        // azione add
        if ($this->azioneNota('add', $data, $docente, $classe)) {
          // pulsante add
          $dati[$data_str]['note']['add'] = $this->router->generate('lezioni_registro_nota_edit', array(
            'classe' => $classe->getId(), 'data' => $data_str));
        }
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce i dati delle assenze per la classe e l'intervallo di date indicato.
   *
   * @param \DateTime $data_inizio Data iniziale del registro
   * @param \DateTime $data_fine Data finale del registro
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Cattedra|null $cattedra Cattedra del docente (se nulla è supplenza)
   *
   * @return array Dati restituiti come array associativo
   */
  public function quadroAssenzeVista(\DateTime $data_inizio, \DateTime $data_fine, Docente $docente, Classe $classe,
                                      Cattedra $cattedra=null) {
    $dati = array();
    if ($data_inizio == $data_fine) {
      // vista giornaliera
      $data_str = $data_inizio->format('Y-m-d');
      $dati[$data_str]['data'] = clone $data_inizio;
      // legge alunni di classe
      $lista = $this->alunniInData($data_inizio, $classe);
      // dati alunni/assenze/ritardi/uscite
      $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
        ->select('a.id AS id_alunno,a.cognome,a.nome,a.dataNascita,a.bes,a.autorizzaEntrata,a.autorizzaUscita,a.note,a.religione,ass.id AS id_assenza,e.id AS id_entrata,e.ora AS ora_entrata,e.note AS note_entrata,u.id AS id_uscita,u.ora AS ora_uscita,u.note AS note_uscita')
        ->leftJoin('AppBundle:Assenza', 'ass', 'WHERE', 'a.id=ass.alunno AND ass.data=:data')
        ->leftJoin('AppBundle:Entrata', 'e', 'WHERE', 'a.id=e.alunno AND e.data=:data')
        ->leftJoin('AppBundle:Uscita', 'u', 'WHERE', 'a.id=u.alunno AND u.data=:data')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $lista, 'data' => $data_str])
        ->getQuery()
        ->getArrayResult();
      // dati giustificazioni
      foreach ($alunni as $k=>$alu) {
        // conteggio assenze da giustificare
        $giustifica_assenze = $this->em->getRepository('AppBundle:Assenza')->createQueryBuilder('ass')
          ->select('COUNT(ass.id)')
          ->where('ass.alunno=:alunno AND ass.data < :data AND ass.giustificato IS NULL')
          ->setParameters(['alunno' => $alu['id_alunno'], 'data' => $data_str])
          ->getQuery()
          ->getSingleScalarResult();
        $alunni[$k]['giustifica_assenze'] = $giustifica_assenze;
        // conteggio ritardi da giustificare
        $giustifica_ritardi = $this->em->getRepository('AppBundle:Entrata')->createQueryBuilder('e')
          ->select('COUNT(e.id)')
          ->where('e.alunno=:alunno AND e.data <= :data AND e.giustificato IS NULL')
          ->setParameters(['alunno' => $alu['id_alunno'], 'data' => $data_str])
          ->getQuery()
          ->getSingleScalarResult();
        $alunni[$k]['giustifica_ritardi'] = $giustifica_ritardi;
        // gestione pulsanti
        $alunno = $this->em->getRepository('AppBundle:Alunno')->find($alu['id_alunno']);
        $pulsanti = $this->azioneAssenze($data_inizio, $docente, $alunno, $classe, ($cattedra ? $cattedra->getMateria() : null));
        if ($pulsanti) {
          // pulsante assenza/presenza
          if ($alu['id_assenza']) {
            // pulsante presenza
            $alunni[$k]['pulsante_presenza'] = $this->router->generate('lezioni_assenze_assenza', array(
              'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str,
              'alunno' => $alu['id_alunno'], 'id' => $alu['id_assenza']));
          } else {
            // pulsante assenza
            $alunni[$k]['pulsante_assenza'] = $this->router->generate('lezioni_assenze_assenza', array(
              'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str,
              'alunno' => $alu['id_alunno'], 'id' => 0));
          }
          // pulsante ritardo
          $alunni[$k]['pulsante_entrata'] = $this->router->generate('lezioni_assenze_entrata', array(
              'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str,
              'alunno' => $alu['id_alunno']));
          // pulsante uscita
          $alunni[$k]['pulsante_uscita'] = $this->router->generate('lezioni_assenze_uscita', array(
              'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str,
              'alunno' => $alu['id_alunno']));
          if (($alunni[$k]['giustifica_assenze'] + $alunni[$k]['giustifica_ritardi'])  > 0) {
            // pulsante giustifica
            $alunni[$k]['pulsante_giustifica'] = $this->router->generate('lezioni_assenze_giustifica', array(
              'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str,
              'alunno' => $alu['id_alunno']));
          }
        }
      }
      $pulsanti = $this->azioneAssenze($data_inizio, $docente, null, $classe, ($cattedra ? $cattedra->getMateria() : null));
      if ($pulsanti) {
        // pulsante appello
        $dati[$data_str]['pulsante_appello'] = $this->router->generate('lezioni_assenze_appello', array(
          'cattedra' => ($cattedra ? $cattedra->getId() : 0), 'classe' => $classe->getId(), 'data' =>$data_str));
      }
      // imposta vettore associativo
      $dati[$data_str]['lista'] = $alunni;
    } else {
      // vista settimanale/mensile
      $lista_alunni = array();
      for ($data = clone $data_inizio; $data <= $data_fine; $data->modify('+1 day')) {
        $data_str = $data->format('Y-m-d');
        $dati['lista'][$data_str]['data'] = clone $data;
        $errore = $this->controlloData($data, $classe->getSede());
        if ($errore) {
          // festivo
          $dati['lista'][$data_str]['errore'] = $errore;
          continue;
        }
        // legge alunni di classe
        $lista = $this->alunniInData($data, $classe);
        $lista_alunni = array_unique(array_merge($lista_alunni, $lista));
        // dati assenze/ritardi/uscite
        $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
          ->select('a.id AS id_alunno,ass.id AS id_assenza,ass.giustificato AS assenza_giust,e.id AS id_entrata,e.ora AS ora_entrata,e.note AS note_entrata,e.giustificato AS entrata_giust,u.id AS id_uscita,u.ora AS ora_uscita,u.note AS note_uscita')
          ->leftJoin('AppBundle:Assenza', 'ass', 'WHERE', 'a.id=ass.alunno AND ass.data=:data')
          ->leftJoin('AppBundle:Entrata', 'e', 'WHERE', 'a.id=e.alunno AND e.data=:data')
          ->leftJoin('AppBundle:Uscita', 'u', 'WHERE', 'a.id=u.alunno AND u.data=:data')
          ->where('a.id IN (:lista)')
          ->setParameters(['lista' => $lista, 'data' => $data_str])
          ->getQuery()
          ->getArrayResult();
        // dati per alunno
        foreach ($alunni as $k=>$alu) {
          $dati['lista'][$data_str][$alu['id_alunno']] = $alu;
        }
      }
      // lista alunni (ordinata)
      $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
        ->select('a.id AS id_alunno,a.cognome,a.nome,a.dataNascita,a.bes,a.autorizzaEntrata,a.autorizzaUscita,a.note,a.religione')
        ->where('a.id IN (:lista)')
        ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
        ->setParameters(['lista' => $lista_alunni])
        ->getQuery()
        ->getArrayResult();
      $dati['alunni'] = $alunni;
    }
    // restituisce vettore associativo
    return $dati;
  }

  /**
   * Restituisce la scansione oraria relativa alla data indicata.
   *
   * @param \DateTime $data Giorno di cui si desidera la scansione oraria
   * @param Sede $sede Sede scolastica
   *
   * @return array Dati restituiti come array associativo
   */
  public function orarioInData(\DateTime $data, Sede $sede) {
    // legge orario
    $scansioneoraria = $this->em->getRepository('AppBundle:ScansioneOraria')->createQueryBuilder('s')
      ->select('s.giorno,s.ora,s.inizio,s.fine,s.durata')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno')
      ->orderBy('s.ora', 'ASC')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $sede, 'giorno' => $data->format('w')])
      ->getQuery()
      ->getScalarResult();
    return $scansioneoraria;
  }

  /**
   * Restituisce la lista degli alunni della classe indicata alla data indicata.
   *
   * @param \DateTime $data Giorno in cui si desidera effettuare il controllo
   * @param Classe $classe Classe scolastica
   *
   * @return array Lista degli ID degli alunni
   */
  public function alunniInData(\DateTime $data, Classe $classe) {
    if ($data->format('Y-m-d') == date('Y-m-d')) {
      // data è quella odierna, legge classe attuale
      $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
        ->select('a.id')
        ->where('a.classe=:classe AND a.abilitato=:abilitato')
        ->setParameters(['classe' => $classe, 'abilitato' => 1])
        ->getQuery()
        ->getScalarResult();
    } else {
      // aggiunge alunni attuali che non hanno fatto cambiamenti di classe in quella data
      $cambio = $this->em->getRepository('AppBundle:CambioClasse')->createQueryBuilder('cc')
        ->where('cc.alunno=a.id AND :data BETWEEN cc.inizio AND cc.fine')
        ->andWhere('cc.classe IS NULL OR cc.classe!=:classe');
      $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
        ->select('a.id')
        ->where('a.classe=:classe AND a.abilitato=:abilitato AND NOT EXISTS ('.$cambio->getDQL().')')
        ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe, 'abilitato' => 1])
        ->getQuery()
        ->getScalarResult();
      // aggiunge altri alunni con cambiamento nella classe in quella data
      $alunni2 = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
        ->select('a.id')
        ->join('AppBundle:CambioClasse', 'cc', 'WHERE', 'a.id=cc.alunno')
        ->where(':data BETWEEN cc.inizio AND cc.fine AND cc.classe=:classe AND a.abilitato=:abilitato')
        ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $classe, 'abilitato' => 1])
        ->getQuery()
        ->getScalarResult();
      $alunni = array_merge($alunni, $alunni2);
    }
    // restituisce lista di ID
    $alunni_id = array_map('current', $alunni);
    return $alunni_id;
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alla gestione delle assenze.
   *
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Alunno $alunno Alunno su cui si esegue l'azione (se nullo su tutta classe)
   * @param Classe $classe Classe della lezione (se nullo tutte le classi)
   * @param Materia $materia Materia della lezione (se nulla è supplenza)
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneAssenze(\DateTime $data, Docente $docente, Alunno $alunno=null, Classe $classe=null,
                                 Materia $materia=null) {
    $oggi = new \DateTime();
    if ($data->format('Y-m-d') <= $oggi->format('Y-m-d')) {
      // data non nel futuro
      if (!$alunno || !$classe || $this->classeInData($data, $alunno) == $classe) {
        // alunno è nella classe indicata
        return true;
      }
    }
    // non consentito
    return false;
  }

  /**
   * Restituisce la classe dell'alunno indicato per la data indicata.
   *
   * @param \DateTime $data Giorno in cui si desidera effettuare il controllo
   * @param Alunno $alunno Alunno di cui si desidera conoscere la classe
   *
   * @return array Lista degli ID degli alunni
   */
  public function classeInData(\DateTime $data, Alunno $alunno) {
    if ($data->format('Y-m-d') == date('Y-m-d')) {
      // data è quella odierna, restituisce la classe attuale
      $classe = $alunno->getClasse();
    } else {
      // cerca cambiamenti di classe in quella data
      $cambio = $this->em->getRepository('AppBundle:CambioClasse')->createQueryBuilder('cc')
        ->where('cc.alunno=:alunno AND :data BETWEEN cc.inizio AND cc.fine')
        ->setParameters(['alunno' => $alunno, 'data' => $data->format('Y-m-d')])
        ->getQuery()
        ->getResult();
      if (count($cambio) == 0) {
        // niente cambi classe, situazione è quella attuale
        $classe = $alunno->getClasse();
      } else {
        // ritorna classe cambiata nel periodo (può essere null)
        return $cambio[0]->getClasse();
      }
    }
    // restituisce classe trovata
    return $classe;
  }

  /**
   * Restituisce la lista delle assenze e dei ritardi da giustificare
   *
   * @param \DateTime $data Data del giorno in cui si giustifica
   * @param Alunno $alunno Alunno da giustificare
   * @param Classe $classe Classe della lezione
   *
   * @return array Dati restituiti come array associativo
   */
  public function assenzeRitardiDaGiustificare(\DateTime $data, Alunno $alunno, Classe $classe) {
    // assenze da giustificare
    $assenze = $this->em->getRepository('AppBundle:Assenza')->createQueryBuilder('ass')
      ->where('ass.alunno=:alunno AND ass.data < :data AND ass.giustificato IS NULL')
      ->orderBy('ass.data', 'DESC')
      ->setParameters(['alunno' => $alunno->getId(), 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getResult();
    // ritardi da giustificare
    $ritardi = $this->em->getRepository('AppBundle:Entrata')->createQueryBuilder('e')
      ->where('e.alunno=:alunno AND e.data <= :data AND e.giustificato IS NULL')
      ->setParameters(['alunno' => $alunno->getId(), 'data' => $data->format('Y-m-d')])
      ->orderBy('e.data', 'DESC')
      ->getQuery()
      ->getResult();
    // restituisce dati
    $dati['assenze'] = $assenze;
    $dati['ritardi'] = $ritardi;
    return $dati;
  }

  /**
   * Restituisce l'elenco degli alunni per la procedura dell'appello
   *
   * @param \DateTime $data Data del giorno in cui si giustifica
   * @param Classe $classe Classe della lezione
   *
   * @return array Lista degli alunni come istanze della classe Appello
   */
  public function elencoAppello(\DateTime $data, Classe $classe) {
    // alunni della classe
    $alunni = $this->alunniInData($data, $classe);
    // legge la lista degli alunni
    $lista = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,ass.id AS assenza,e.id AS entrata,e.ora')
      ->leftJoin('AppBundle:Assenza', 'ass', 'WHERE', 'a.id=ass.alunno AND ass.data=:data')
      ->leftJoin('AppBundle:Entrata', 'e', 'WHERE', 'a.id=e.alunno AND e.data=:data')
      ->where('a.id IN (:id)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['id' => $alunni, 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    // crea l'elenco per l'appello
    $elenco = array();
    $orario = $this->orarioInData($data, $classe->getSede());
    foreach ($lista as $elemento) {
      $appello = (new Appello())
        ->setId($elemento['id'])
        ->setAlunno($elemento['cognome'].' '.$elemento['nome'].' ('.$elemento['dataNascita']->format('d/m/Y').')')
        ->setPresenza($elemento['assenza'] ? 'A' : ($elemento['entrata'] ? 'R' : 'P'))
        ->setOra($elemento['ora'] ? $elemento['ora'] : new \DateTime());
      if ($appello->getOra()->format('H:i:00') < $orario[0]['inizio'] ||
          $appello->getOra()->format('H:i:00') > $orario[count($orario) - 1]['fine']) {
        // ora fuori da orario
        $appello->setOra(\DateTime::createFromFormat('H:i:s', $orario[0]['inizio']));
      }
      $elenco[$elemento['id']] = $appello;
    }
    // restituisce elenco
    return $elenco;
  }

  /**
   * Controlla se esiste una cattedra con le caratteristiche indicate (escluso sostegno)
   *
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Materia $materia Materia della lezione
   *
   * @return bool Restituisce vero se la cattedra esiste
   */
  public function esisteCattedra(Docente $docente, Classe $classe, Materia $materia) {
    $cattedra = $this->em->getRepository('AppBundle:Cattedra')->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->where('c.docente=:docente AND c.classe=:classe AND c.materia=:materia AND c.attiva=:attiva AND c.tipo!=:tipo')
      ->setParameters(['docente' => $docente, 'classe' => $classe, 'materia' => $materia, 'attiva' => 1,
        'tipo' => 'S'])
      ->getQuery()
      ->getSingleScalarResult();
    return ($cattedra > 0);
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alla gestione dei voti.
   *
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Alunno $alunno Alunno su cui si esegue l'azione (se nullo su tutta classe)
   * @param Classe $classe Classe della lezione
   * @param Materia $materia Materia della lezione
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneVoti(\DateTime $data, Docente $docente, Alunno $alunno=null, Classe $classe, Materia $materia) {
    $oggi = new \DateTime();
    if ($data->format('Y-m-d') <= $oggi->format('Y-m-d')) {
      // data non nel futuro
      if (!$alunno || $this->classeInData($data, $alunno) == $classe) {
        // alunno è nella classe indicata
        if ($materia && $this->esisteCattedra($docente, $classe, $materia)) {
          // non è supplenza e esiste la cattedra (non di sostegno)
          return true;
        }
      }
    }
    // non consentito
    return false;
  }

  /**
   * Restituisce la lezione del docente nella data e cattedra definita (escluso sostegno)
   *
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param Materia $materia Materia della lezione
   *
   * @return int|null Restituisce l'identificatore della lezione o null se non trovata
   */
  public function lezioneCattedra(\DateTime $data, Docente $docente, Classe $classe, Materia $materia) {
    $lezione = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->join('AppBundle:Firma', 'f', 'WHERE', 'l.id=f.lezione')
      ->where('l.data=:data AND l.classe=:classe AND l.materia=:materia AND f.docente=:docente')
      ->setParameters(['data' => $data->format('Y-m-d'), 'docente' => $docente, 'classe' => $classe,
        'materia' => $materia])
      ->orderBy('l.ora', 'ASC')
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
    return $lezione;
  }

  /**
   * Restituisce l'elenco degli alunni per l'assegnamento di un voto alla classe
   *
   * @param \DateTime $data Data del giorno in cui si fa la verifica
   * @param Docente $docente Docente che attribuisce il voto
   * @param Classe $classe Classe in cui si attribuisce il voto
   * @param Materia $materia Materia per cui si attribuisce il voto
   * @param string $tipo Tipo di voto (S,O,P)
   * @param string $argomento Argomenti o descrizione della prova (valore restituito)
   * @param bool $visibile Se è visibile ai geniori (valore restituito)
   *
   * @return array Lista degli alunni come istanze della classe VotoClasse
   */
  public function elencoVoto(\DateTime $data, Docente $docente, Classe $classe, Materia $materia,
                              $tipo, &$argomento, &$visibile) {
    $elenco = array();
    $argomento = null;
    $visibile = null;
    // alunni della classe
    $lista_alunni = $this->alunniInData($data, $classe);
    // legge i dati degli degli alunni
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.bes')
      ->where('a.id IN (:alunni)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['alunni' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    foreach ($alunni as $alu) {
      $voto = (new VotoClasse())
        ->setId($alu['id'])
        ->setAlunno($alu['cognome'].' '.$alu['nome'].' ('.$alu['dataNascita']->format('d/m/Y').')')
        ->setBes($alu['bes']);
      $elenco[$alu['id']] = $voto;
    }
    // legge i voti degli degli alunni
    $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
      ->select('a.id AS alunno_id,v.id,v.argomento,v.visibile,v.voto,v.giudizio')
      ->join('v.alunno', 'a')
      ->join('v.lezione', 'l')
      ->where('a.id IN (:alunni) AND v.docente=:docente AND v.tipo=:tipo AND l.materia=:materia AND l.classe=:classe AND l.data=:data')
      ->setParameters(['alunni' => $lista_alunni, 'docente' => $docente, 'tipo' => $tipo,
        'materia' => $materia, 'classe' => $classe, 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    foreach ($voti as $v) {
      if ($v['voto'] > 0) {
        $voto_int = intval($v['voto'] + 0.25);
        $voto_dec = $v['voto'] - intval($v['voto']);
        $voto_str = $voto_int.($voto_dec == 0.25 ? '+' : ($voto_dec == 0.75 ? '-' : ($voto_dec == 0.5 ? '½' : '')));
      } else {
        $voto_str = '--';
      }
      $elenco[$v['alunno_id']]
        ->setVoto($v['voto'])
        ->setVotoTesto($voto_str)
        ->setGiudizio($v['giudizio'])
        ->setVotoId($v['id']);
      if (!$argomento && $v['argomento'] != '') {
        $argomento = trim($v['argomento']);
      }
      if ($visibile === null && $v['visibile'] !== null) {
        $visibile = $v['visibile'] ? '1' : '0';
      }
    }
    if ($visibile === null) {
      $visibile = '1';
    }
    // restituisce elenco
    return $elenco;
  }

  /**
   * Restituisce il voto di un alunno per la data e il tipo specificato
   *
   * @param \DateTime $data Data del giorno in cui si fa la verifica
   * @param Docente $docente Docente della lezione
   * @param Alunno $alunno Alunno di cui si cerca il voto
   * @param Lezione $lezione Lezione in cui si attribuisce il voto
   * @param string $tipo Tipo di voto (S,O,P)
   *
   * @return Valutazione|null Oggetto Valutazione o null se non trovato
   */
  public function alunnoVoto(\DateTime $data, Docente $docente, Alunno $alunno, Lezione $lezione, $tipo) {
    // legge il voto
    $valutazione = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
      ->where('v.alunno=:alunno AND v.docente=:docente AND v.lezione=:lezione AND v.tipo=:tipo')
      ->setParameters(['alunno' => $alunno, 'docente' => $docente, 'lezione' => $lezione, 'tipo' => $tipo])
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
    return $valutazione;
  }

  /**
   * Restituisce il periodo dell'anno scolastico in base alla data
   *
   * @param \DateTime $data Data di cui indicare il periodo
   *
   * @return array Informazioni sul periodo come valori di array associativo
   */
  public function periodo(\DateTime $data) {
    $dati = array();
    $data_str = $data->format('Y-m-d');
    if ($data_str <= $this->session->get('/CONFIG/SCUOLA/periodo1_fine')) {
      // primo periodo
      $dati['periodo'] = 1;
      $dati['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo1_nome');
      $dati['inizio'] = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/anno_inizio').' 00:00');
      $dati['fine'] = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/periodo1_fine').' 00:00');
    } elseif ($data_str <= $this->session->get('/CONFIG/SCUOLA/periodo2_fine')) {
      // secondo periodo
      $dati['periodo'] = 2;
      $dati['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo2_nome');
      $data = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/periodo1_fine').' 00:00');
      $data->modify('+1 day');
      $dati['inizio'] = $data;
      $dati['fine'] = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/periodo2_fine').' 00:00');
    } elseif ($this->session->get('/CONFIG/SCUOLA/periodo3_fine') == 'true' &&
              $data_str <= $this->session->get('/CONFIG/SCUOLA/anno_fine')) {
      // terzo periodo
      $dati['periodo'] = 3;
      $dati['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo3_nome');
      $data = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/periodo2_fine').' 00:00');
      $data->modify('+1 day');
      $dati['inizio'] = $data;
      $dati['fine'] = \DateTime::createFromFormat('Y-m-d H:i', $this->session->get('/CONFIG/SCUOLA/anno_fine').' 00:00');
    } else {
      // errore
      $dati = null;
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce i dati dei voti per la classe e l'intervallo di date indicato.
   *
   * @param \DateTime $data_inizio Data iniziale del registro
   * @param \DateTime $data_fine Data finale del registro
   * @param Docente $docente Docente della lezione
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function quadroVoti(\DateTime $data, \DateTime $data_inizio, \DateTime $data_fine, Docente $docente,
                              Cattedra $cattedra) {
    $data_str = $data->format('Y-m-d');
    $dati = array();
    // alunni della classe
    $lista_alunni = $this->alunniInData($data, $cattedra->getClasse());
    // legge i dati degli degli alunni
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.bes,a.note')
      ->where('a.id IN (:alunni)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['alunni' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    foreach ($alunni as $alu) {
      $dati[$alu['id']] = $alu;
      $dati[$alu['id']]['S']['precedente'] = array();
      $dati[$alu['id']]['S']['successivo'] = array();
      $dati[$alu['id']]['S']['attuale'] = array();
      $dati[$alu['id']]['O']['precedente'] = array();
      $dati[$alu['id']]['O']['successivo'] = array();
      $dati[$alu['id']]['O']['attuale'] = array();
      $dati[$alu['id']]['P']['precedente'] = array();
      $dati[$alu['id']]['P']['successivo'] = array();
      $dati[$alu['id']]['P']['attuale'] = array();
    }
    // legge i voti degli degli alunni
    $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
      ->select('a.id AS alunno_id,v.id,v.tipo,v.argomento,v.visibile,v.voto,v.giudizio,l.data')
      ->join('v.alunno', 'a')
      ->join('v.lezione', 'l')
      ->where('a.id IN (:alunni) AND v.docente=:docente AND l.materia=:materia AND l.classe=:classe AND l.data BETWEEN :inizio AND :fine')
      ->orderBy('l.data', 'ASC')
      ->setParameters(['alunni' => $lista_alunni, 'docente' => $docente, 'materia' => $cattedra->getMateria(),
        'classe' => $cattedra->getClasse(), 'inizio' => $data_inizio->format('Y-m-d'),
        'fine' => $data_fine->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    foreach ($voti as $v) {
      if ($v['voto'] > 0) {
        $voto_int = intval($v['voto'] + 0.25);
        $voto_dec = $v['voto'] - intval($v['voto']);
        $v['voto_str'] = $voto_int.($voto_dec == 0.25 ? '+' : ($voto_dec == 0.75 ? '-' : ($voto_dec == 0.5 ? '½' : '')));
      }
      if ($v['data']->format('Y-m-d') < $data_str) {
        $dati[$v['alunno_id']][$v['tipo']]['precedente'][] = $v;
      } elseif ($v['data']->format('Y-m-d') > $data_str) {
        $dati[$v['alunno_id']][$v['tipo']]['successivo'][] = $v;
      } else {
        $dati[$v['alunno_id']][$v['tipo']]['attuale'][] = $v;
      }
    }
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce le informazioni sui periodi dell'anno scolastico
   *
   * @return array Informazioni sui periodi come valori di array associativo
   */
  public function infoPeriodi() {
    $dati = array();
    // primo periodo
    $dati[1]['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo1_nome');
    $dati[1]['fine'] = $this->session->get('/CONFIG/SCUOLA/periodo1_fine');
    // secondo periodo
    $dati[2]['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo2_nome');
    $dati[2]['fine'] = $this->session->get('/CONFIG/SCUOLA/periodo2_fine');
    // terzo periodo
    $dati[3]['nome'] = $this->session->get('/CONFIG/SCUOLA/periodo3_nome');
    $dati[3]['fine'] = $this->session->get('/CONFIG/SCUOLA/anno_fine');
    // restituisce dati
    return $dati;
  }

  /**
   * Restituisce vero se si tratta di un ritardo breve
   *
   * @param \DateTime $data Data dell'entrata in ritardo
   * @param \DateTime $ora Ora dell'entrata in ritardo
   * @param Sede $sede Sede della classe
   *
   * @return bool Vero se è un ritardo breve, falso altrimenti
   */
  public function seRitardoBreve(\DateTime $data, \DateTime $ora, Sede $sede) {
    // legge prima ora
    $prima = $this->em->getRepository('AppBundle:ScansioneOraria')->createQueryBuilder('s')
      ->select('s.inizio')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno AND s.ora=:ora')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $sede, 'giorno' => $data->format('w'), 'ora' => 1])
      ->getQuery()
      ->getArrayResult();
    // controlla ritardo breve
    $inizio = $prima[0]['inizio'];
    $inizio->modify('+' . $this->session->get('/CONFIG/SCUOLA/ritardo_breve', 0) . 'minutes');
    return ($ora <= $inizio);
  }

  /**
   * Ricalcola le ore di assenza dell'alunno per la data indicata
   *
   * @param \DateTime $data Data a cui si riferisce il calcolo delle assenze
   * @param Alunno $alunno Alunno a cui si riferisce il calcolo delle assenze
   */
  public function ricalcolaOreAlunno(\DateTime $data, Alunno $alunno) {
    $this->em->getConnection()->beginTransaction();
    // lezioni del giorno
    $lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->select('l.id,s.ora,s.inizio,s.fine,s.durata')
      ->join('AppBundle:ScansioneOraria', 's', 'WHERE', 'l.ora=s.ora AND s.giorno=:giorno')
      ->join('s.orario', 'o')
      ->where('l.data=:data AND l.classe=:classe AND :data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
      ->setParameters(['data' => $data->format('Y-m-d'), 'classe' => $alunno->getClasse(),
        'sede' => $alunno->getClasse()->getSede(), 'giorno' => $data->format('w')])
      ->getQuery()
      ->getArrayResult();
    // elimina ore assenze esistenti
    $this->em->getConnection()
      ->prepare('DELETE FROM gs_assenza_lezione WHERE alunno_id=:alunno AND lezione_id IN (SELECT id FROM gs_lezione WHERE data=:data AND classe_id=:classe)')
      ->execute(['alunno' => $alunno->getId(), 'data' => $data->format('Y-m-d'),
        'classe' => $alunno->getClasse()->getId()]);
    // legge assenza del giorno
    $assenza = $this->em->getRepository('AppBundle:Assenza')->findOneBy(['alunno' => $alunno, 'data' => $data]);
    if ($assenza) {
      // aggiunge ore assenza
      foreach ($lezioni as $l) {
        $this->em->getConnection()
          ->prepare('INSERT INTO gs_assenza_lezione (modificato,alunno_id,lezione_id,ore) VALUES (NOW(),:alunno,:lezione,:durata)')
          ->execute(['lezione' => $l['id'], 'alunno' => $alunno->getId(),
            'durata' => $l['durata'] / 60.0]);
      }
    } else {
      // aggiunge ore assenza se esiste ritardo/uscita
      $entrata = $this->em->getRepository('AppBundle:Entrata')->findOneBy(['alunno' => $alunno, 'data' => $data]);
      $uscita = $this->em->getRepository('AppBundle:Uscita')->findOneBy(['alunno' => $alunno, 'data' => $data]);
      if ($entrata || $uscita) {
        // calcolo periodo in cui è assente
        foreach ($lezioni as $l) {
          $assenza = 0;
          if ($entrata && $entrata->getOra() > $l['inizio']) {
            // calcola minuti entrata
            $assenza = min(($entrata->getOra()->getTimestamp() - $l['inizio']->getTimestamp()) / 60, $l['durata']);
          }
          if ($uscita && $uscita->getOra() < $l['fine']) {
            // calcola minuti uscita
            $assenza = min(($assenza + ($l['fine']->getTimestamp() - $uscita->getOra()->getTimestamp()) / 60), $l['durata']);
          }
          // approssimazione per difetto alla mezz'ora (non si danneggia alunno)
          $oreassenza = intval($assenza / 30) * 0.5;
          if ($oreassenza > 0) {
            // aggiunge ore assenza
            $this->em->getConnection()
              ->prepare('INSERT INTO gs_assenza_lezione (modificato,alunno_id,lezione_id,ore) VALUES (NOW(),:alunno,:lezione,:durata)')
              ->execute(['lezione' => $l['id'], 'alunno' => $alunno->getId(), 'durata' => $oreassenza]);
          }
        }
      }
    }
    $this->em->getConnection()->commit();
  }

  /**
   * Ricalcola le ore di assenza per la nuova lezione inserita nella data indicata
   *
   * @param \DateTime $data Data a cui si riferisce il calcolo delle assenze
   * @param Lezione $lezione Lezione a cui si riferisce il calcolo delle assenze
   */
  public function ricalcolaOreLezione(\DateTime $data, Lezione $lezione) {
    $this->em->getConnection()->beginTransaction();
    // orario lezione
    $ora = $this->em->getRepository('AppBundle:ScansioneOraria')->createQueryBuilder('s')
      ->select('s.inizio,s.fine,s.durata')
      ->join('s.orario', 'o')
      ->where(':data BETWEEN o.inizio AND o.fine AND o.sede=:sede AND s.giorno=:giorno AND s.ora=:ora')
      ->setParameters(['data' => $data->format('Y-m-d'), 'sede' => $lezione->getClasse()->getSede(),
        'giorno' => $data->format('w'), 'ora' => $lezione->getOra()])
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();
    // legge alunni di classe
    $lista = $this->alunniInData($data, $lezione->getClasse());
    // dati alunni/assenze/ritardi/uscite
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id AS id_alunno,ass.id AS id_assenza,e.id AS id_entrata,e.ora AS ora_entrata,u.id AS id_uscita,u.ora AS ora_uscita')
      ->leftJoin('AppBundle:Assenza', 'ass', 'WHERE', 'a.id=ass.alunno AND ass.data=:data')
      ->leftJoin('AppBundle:Entrata', 'e', 'WHERE', 'a.id=e.alunno AND e.data=:data')
      ->leftJoin('AppBundle:Uscita', 'u', 'WHERE', 'a.id=u.alunno AND u.data=:data')
      ->where('a.id IN (:lista)')
      ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    // calcola ore assenza
    foreach ($alunni as $alu) {
      if ($alu['id_assenza']) {
        // assente
        $this->em->getConnection()
          ->prepare('INSERT INTO gs_assenza_lezione (modificato,alunno_id,lezione_id,ore) VALUES (NOW(),:alunno,:lezione,:durata)')
          ->execute(['lezione' => $lezione->getId(), 'alunno' => $alu['id_alunno'],
            'durata' => $ora['durata'] / 60.0]);
      } elseif ($alu['id_entrata'] || $alu['id_uscita']) {
        // entrata o uscita o entrambi
        $assenza = 0;
        if ($alu['id_entrata'] && $alu['ora_entrata'] > $ora['inizio']) {
          // calcola minuti entrata
          $assenza = min(($alu['ora_entrata']->getTimestamp() - $ora['inizio']->getTimestamp()) / 60, $ora['durata']);
        }
        if ($alu['id_uscita'] && $alu['ora_uscita'] < $ora['fine']) {
          // calcola minuti uscita
          $assenza = min(($assenza + ($ora['fine']->getTimestamp() - $alu['ora_uscita']->getTimestamp()) / 60), $ora['durata']);
        }
        // approssimazione per difetto alla mezz'ora (non si danneggia alunno)
        $oreassenza = intval($assenza / 30) * 0.5;
        if ($oreassenza > 0) {
          // aggiunge ore assenza
          $this->em->getConnection()
            ->prepare('INSERT INTO gs_assenza_lezione (modificato,alunno_id,lezione_id,ore) VALUES (NOW(),:alunno,:lezione,:durata)')
            ->execute(['lezione' => $lezione->getId(), 'alunno' => $alu['id_alunno'],
              'durata' => $oreassenza]);
        }
      }
    }
    $this->em->getConnection()->commit();
  }

  /**
   * Restituisce gli argomenti per la cattedra indicata.
   *
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function argomenti(Cattedra $cattedra) {
    // inizializza
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $periodi = $this->infoPeriodi();
    $dati = array();
    if ($cattedra->getTipo() == 'S') {
      // cattedra di sostegno
      return $this->argomentiSostegno($cattedra);
    }
    // cattedra non di sostegno
    $lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->select('l.id,l.data,l.ora,l.argomento,l.attivita,d.id AS docente')
      ->leftJoin('AppBundle:Firma', 'f', 'WHERE', 'l.id=f.lezione AND f.docente=:docente')
      ->leftJoin('f.docente', 'd')
      ->where('l.classe=:classe AND l.materia=:materia')
      ->orderBy('l.data', 'DESC')
      ->addOrderBy('l.ora', 'ASC')
      ->setParameters(['classe' => $cattedra->getClasse(), 'materia' => $cattedra->getMateria(),
        'docente' => $cattedra->getDocente()])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo
    $data_prec = null;
    $num = 0;
    foreach ($lezioni as $l) {
      $data = $l['data']->format('Y-m-d');
      $firme = '';
      if (!$l['docente']) {
        // legge altre firme
        $docenti = $this->em->getRepository('AppBundle:Firma')->createQueryBuilder('f')
          ->select('d.nome,d.cognome')
          ->join('f.docente', 'd')
          ->where('f.lezione=:lezione AND f.docente!=:docente AND f NOT INSTANCE OF AppBundle:FirmaSostegno')
          ->orderBy('d.cognome,d.nome', 'ASC')
          ->setParameters(['lezione' => $l['id'], 'docente' => $cattedra->getDocente()])
          ->getQuery()
          ->getArrayResult();
        $lista_firme = array();
        foreach ($docenti as $d) {
          $lista_firme[] = $d['nome'].' '.$d['cognome'];
        }
        $firme = implode(', ', $lista_firme);
      }
      if ($data_prec && $data != $data_prec) {
        if ($num == 0) {
          // nessun argomento in data precedente
          $periodo = ($data_prec <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
            ($data_prec <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
          $data_str = intval(substr($data_prec, 8)).' '.$mesi[intval(substr($data_prec, 5, 2))];
          $dati[$periodo][$data_prec][$num]['data'] = $data_str;
          $dati[$periodo][$data_prec][$num]['argomento'] = '';
          $dati[$periodo][$data_prec][$num]['attivita'] = '';
          $dati[$periodo][$data_prec][$num]['firme'] = '';
        } else {
          // fa ripartire contatore
          $num = 0;
        }
      }
      if (trim($l['argomento'].$l['attivita']) != '' || $firme != '') {
        // argomento presente o altro docente
        if ($num == 0 || $firme != '' ||
            strcasecmp($l['argomento'], $dati[$periodo][$data][$num-1]['argomento']) ||
            strcasecmp($l['attivita'], $dati[$periodo][$data][$num-1]['attivita'])) {
          // evita ripetizioni identiche degli argomenti
          $periodo = ($data <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
            ($data <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
          $data_str = intval(substr($data, 8)).' '.$mesi[intval(substr($data, 5, 2))];
          $dati[$periodo][$data][$num]['data'] = $data_str;
          $dati[$periodo][$data][$num]['argomento'] = $l['argomento'];
          $dati[$periodo][$data][$num]['attivita'] = $l['attivita'];
          $dati[$periodo][$data][$num]['firme'] = $firme;
          $num++;
        }
      }
      $data_prec = $data;
    }
    if ($data_prec && $num == 0) {
      // nessun argomento in data precedente
      $periodo = ($data_prec <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
        ($data_prec <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
      $data_str = intval(substr($data_prec, 8)).' '.$mesi[intval(substr($data_prec, 5, 2))];
      $dati[$periodo][$data_prec][$num]['data'] = $data_str;
      $dati[$periodo][$data_prec][$num]['argomento'] = '';
      $dati[$periodo][$data_prec][$num]['attivita'] = '';
      $dati[$periodo][$data_prec][$num]['firme'] = '';
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce gli argomenti del sostegno per la cattedra indicata.
   *
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function argomentiSostegno(Cattedra $cattedra) {
    // inizializza
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $periodi = $this->infoPeriodi();
    $dati = array();
    // legge lezioni
    $lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->select('l.data,l.ora,l.argomento,l.attivita,fs.argomento AS argomento_sost,fs.attivita AS attivita_sost,m.nomeBreve')
      ->join('l.materia', 'm')
      ->join('AppBundle:FirmaSostegno', 'fs', 'WHERE', 'l.id=fs.lezione')
      ->where('l.classe=:classe AND fs.alunno=:alunno')
      ->orderBy('l.data', 'DESC')
      ->addOrderBy('m.nomeBreve,l.ora', 'ASC')
      ->setParameters(['classe' => $cattedra->getClasse(), 'alunno' => $cattedra->getAlunno()])
      ->getQuery()
      ->getArrayResult();
    // imposta array associativo
    $data_prec = null;
    $materia_prec = null;
    $num = 0;
    foreach ($lezioni as $l) {
      $data = $l['data']->format('Y-m-d');
      $materia = $l['nomeBreve'];
      if ($data_prec && ($data != $data_prec || $materia != $materia_prec)) {
        if ($num == 0) {
          // nessun argomento
          $periodo = ($data_prec <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
            ($data_prec <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
          $data_str = intval(substr($data_prec, 8)).' '.$mesi[intval(substr($data_prec, 5, 2))];
          $dati[$periodo][$data_prec][$materia_prec][$num]['data'] = $data_str;
          $dati[$periodo][$data_prec][$materia_prec][$num]['argomento'] = '';
          $dati[$periodo][$data_prec][$materia_prec][$num]['attivita'] = '';
          $dati[$periodo][$data_prec][$materia_prec][$num]['argomento_sost'] = '';
          $dati[$periodo][$data_prec][$materia_prec][$num]['attivita_sost'] = '';
        } else {
          // fa ripartire contatore
          $num = 0;
        }
      }
      if (trim($l['argomento'].$l['attivita'].$l['argomento_sost'].$l['attivita_sost']) != '') {
        // argomento presente
        if ($num == 0 || strcasecmp($l['argomento'], $dati[$periodo][$data][$materia][$num-1]['argomento']) ||
            strcasecmp($l['attivita'], $dati[$periodo][$data][$materia][$num-1]['attivita']) ||
            strcasecmp($l['argomento_sost'], $dati[$periodo][$data][$materia][$num-1]['argomento_sost']) ||
            strcasecmp($l['attivita_sost'], $dati[$periodo][$data][$materia][$num-1]['attivita_sost'])) {
          // evita ripetizioni identiche di argomenti
          $periodo = ($data <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
            ($data <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
          $data_str = intval(substr($data, 8)).' '.$mesi[intval(substr($data, 5, 2))];
          $dati[$periodo][$data][$materia][$num]['data'] = $data_str;
          $dati[$periodo][$data][$materia][$num]['argomento'] = $l['argomento'];
          $dati[$periodo][$data][$materia][$num]['attivita'] = $l['attivita'];
          $dati[$periodo][$data][$materia][$num]['argomento_sost'] = $l['argomento_sost'];
          $dati[$periodo][$data][$materia][$num]['attivita_sost'] = $l['attivita_sost'];
          $num++;
        }
      }
      $data_prec = $data;
      $materia_prec = $materia;
    }
    if ($data_prec && $num == 0) {
      // nessun argomento
      $periodo = ($data_prec <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
        ($data_prec <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
      $data_str = intval(substr($data_prec, 8)).' '.$mesi[intval(substr($data_prec, 5, 2))];
      $dati[$periodo][$data_prec][$materia_prec][$num]['data'] = $data_str;
      $dati[$periodo][$data_prec][$materia_prec][$num]['argomento'] = '';
      $dati[$periodo][$data_prec][$materia_prec][$num]['attivita'] = '';
      $dati[$periodo][$data_prec][$materia_prec][$num]['argomento_sost'] = '';
      $dati[$periodo][$data_prec][$materia_prec][$num]['attivita_sost'] = '';
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce solo le informazioni sulle assenze per la classe e la data indicata.
   *
   * @param \DateTime $data Data del registro
   * @param Classe $classe Classe della lezione
   *
   * @return array Dati restituiti come array associativo
   */
  public function listaAssenti(\DateTime $data, Classe $classe) {
    $dati = array();
    // legge alunni di classe
    $lista = $this->alunniInData($data, $classe);
    // dati alunni/assenze/ritardi/uscite
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id AS id_alunno,a.cognome,a.nome,a.dataNascita,ass.id AS id_assenza,e.id AS id_entrata,e.ora AS ora_entrata,u.id AS id_uscita,u.ora AS ora_uscita')
      ->leftJoin('AppBundle:Assenza', 'ass', 'WHERE', 'a.id=ass.alunno AND ass.data=:data')
      ->leftJoin('AppBundle:Entrata', 'e', 'WHERE', 'a.id=e.alunno AND e.data=:data')
      ->leftJoin('AppBundle:Uscita', 'u', 'WHERE', 'a.id=u.alunno AND u.data=:data')
      ->where('a.id IN (:lista)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['lista' => $lista, 'data' => $data->format('Y-m-d')])
      ->getQuery()
      ->getArrayResult();
    // imposta vettore associativo
    foreach ($alunni as $alu) {
      if ($alu['id_assenza']) {
        $dati['assenze'][] = $alu['cognome'].' '.$alu['nome'];
      }
      if ($alu['id_entrata']) {
        $dati['entrate'][] = $alu['cognome'].' '.$alu['nome'].' ('.$alu['ora_entrata']->format('H:i').')';
      }
      if ($alu['id_uscita']) {
        $dati['uscite'][] = $alu['cognome'].' '.$alu['nome'].' ('.$alu['ora_uscita']->format('H:i').')';
      }
    }
    // restituisce vettore associativo
    return $dati;
  }

  /**
   * Restituisce il riepilogo mensile delle lezioni per la cattedra indicata.
   *
   * @param \DateTime $data Data per il riepilogo mensile
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function riepilogo(\DateTime $data, Cattedra $cattedra) {
    // inizializza
    $dati = array();
    if ($cattedra->getTipo() == 'S') {
      // cattedra di sostegno
      return $this->riepilogoSostegno($data, $cattedra);
    }
    // legge lezioni
    $lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->select('l.id,l.data,l.ora,so.durata')
      ->join('AppBundle:Firma', 'f', 'WHERE', 'l.id=f.lezione AND f.docente=:docente')
      ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
      ->join('so.orario', 'o')
      ->where('l.classe=:classe AND l.materia=:materia AND MONTH(l.data)=:mese AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
      ->orderBy('l.data,l.ora', 'ASC')
      ->setParameters(['classe' => $cattedra->getClasse(), 'materia' => $cattedra->getMateria(),
        'docente' => $cattedra->getDocente(), 'mese' => intval($data->format('m')),
        'sede' => $cattedra->getClasse()->getSede()])
      ->getQuery()
      ->getArrayResult();
    // legge assenze/voti
    $lista = array();
    $lista_alunni = array();
    $data_prec = null;
    foreach ($lezioni as $l) {
      if (!$data_prec || $l['data'] != $data_prec) {
        // cambio di data
        $data_str = $l['data']->format('Y-m-d');
        $dati['lista'][$data_str]['data'] = intval($l['data']->format('d'));
        $dati['lista'][$data_str]['durata'] = 0;
        $lista = $this->alunniInData($l['data'], $cattedra->getClasse());
        $lista_alunni = array_unique(array_merge($lista_alunni, $lista));
        // alunni in classe per data
        foreach ($lista as $id) {
          $dati['lista'][$data_str][$id]['classe'] = 1;
        }
      }
      // aggiorna durata lezioni
      $dati['lista'][$data_str]['durata'] += $l['durata'] / 60;
      // legge assenze
      $assenze = $this->em->getRepository('AppBundle:AssenzaLezione')->createQueryBuilder('al')
        ->select('(al.alunno) AS id,al.ore')
        ->where('al.lezione=:lezione')
        ->setParameters(['lezione' => $l['id']])
        ->getQuery()
        ->getArrayResult();
      // somma ore di assenza per alunno
      foreach ($assenze as $a) {
        if (isset($dati['lista'][$data_str][$a['id']]['assenze'])) {
          $dati['lista'][$data_str][$a['id']]['assenze'] += $a['ore'];
        } else {
          $dati['lista'][$data_str][$a['id']]['assenze'] = $a['ore'];
        }
      }
      // legge voti
      $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
        ->select('(v.alunno) AS id,v.id AS voto_id,v.tipo,v.visibile,v.voto,v.giudizio,v.argomento')
        ->where('v.lezione=:lezione AND v.docente=:docente')
        ->setParameters(['lezione' => $l['id'], 'docente' => $cattedra->getDocente()])
        ->getQuery()
        ->getArrayResult();
      // voti per alunno
      foreach ($voti as $v) {
        if ($v['voto'] > 0) {
          $voto_int = intval($v['voto'] + 0.25);
          $voto_dec = $v['voto'] - intval($v['voto']);
          $v['voto_str'] = $voto_int.($voto_dec == 0.25 ? '+' : ($voto_dec == 0.75 ? '-' : ($voto_dec == 0.5 ? '½' : '')));
        }
        $dati['lista'][$data_str][$v['id']]['voti'][] = $v;
      }
      // memorizza data precedente
      $data_prec = $l['data'];
    }
    // lista alunni (ordinata)
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.religione,a.bes,a.note')
      ->where('a.id IN (:lista)')
      ->orderBy('a.cognome,a.nome,a.dataNascita', 'ASC')
      ->setParameters(['lista' => $lista_alunni])
      ->getQuery()
      ->getArrayResult();
    $dati['alunni'] = $alunni;
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce il riepilogo mensile delle lezioni per la cattedra di sostegno indicata.
   *
   * @param \DateTime $data Data per il riepilogo mensile
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function riepilogoSostegno(\DateTime $data, Cattedra $cattedra) {
    // inizializza
    $dati = array();
    $alunno = $cattedra->getAlunno()->getId();
    // legge lezioni
    $lezioni = $this->em->getRepository('AppBundle:Lezione')->createQueryBuilder('l')
      ->select('l.id,l.data,l.ora,so.durata')
      ->join('AppBundle:FirmaSostegno', 'fs', 'WHERE', 'l.id=fs.lezione AND fs.docente=:docente AND fs.alunno=:alunno')
      ->join('AppBundle:ScansioneOraria', 'so', 'WHERE', 'l.ora=so.ora AND (WEEKDAY(l.data)+1)=so.giorno')
      ->join('so.orario', 'o')
      ->where('l.classe=:classe AND MONTH(l.data)=:mese AND l.data BETWEEN o.inizio AND o.fine AND o.sede=:sede')
      ->orderBy('l.data,l.ora', 'ASC')
      ->setParameters(['classe' => $cattedra->getClasse(), 'docente' => $cattedra->getDocente(),
        'alunno' => $alunno, 'mese' => intval($data->format('m')), 'sede' => $cattedra->getClasse()->getSede()])
      ->getQuery()
      ->getArrayResult();
    // legge assenze
    $data_prec = null;
    foreach ($lezioni as $l) {
      if (!$data_prec || $l['data'] != $data_prec) {
        // cambio di data
        $data_str = $l['data']->format('Y-m-d');
        $dati['lista'][$data_str]['data'] = intval($l['data']->format('d'));
        $dati['lista'][$data_str]['durata'] = 0;
        if ($this->classeInData($l['data'], $cattedra->getAlunno()) == $cattedra->getClasse()) {
          $dati['lista'][$data_str][$alunno]['classe'] = 1;
        }
      }
      // aggiorna durata lezioni
      $dati['lista'][$data_str]['durata'] += $l['durata'] / 60;
      // legge assenze
      $assenze = $this->em->getRepository('AppBundle:AssenzaLezione')->createQueryBuilder('al')
        ->select('al.ore')
        ->where('al.lezione=:lezione AND al.alunno=:alunno')
        ->setParameters(['lezione' => $l['id'], 'alunno' => $alunno])
        ->getQuery()
        ->getArrayResult();
      // somma ore di assenza per l'alunno
      foreach ($assenze as $a) {
        if (isset($dati['lista'][$data_str][$alunno]['assenze'])) {
          $dati['lista'][$data_str][$alunno]['assenze'] += $a['ore'];
        } else {
          $dati['lista'][$data_str][$alunno]['assenze'] = $a['ore'];
        }
      }
      // memorizza data precedente
      $data_prec = $l['data'];
    }
    // info alunno
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.id,a.cognome,a.nome,a.dataNascita,a.religione,a.bes,a.note')
      ->where('a.id=:alunno')
      ->setParameters(['alunno' => $alunno])
      ->getQuery()
      ->getArrayResult();
    $dati['alunni'] = $alunni;
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Controlla se è possibile eseguire l'azione specificata relativamente alle osservazioni.
   *
   * @param string $azione Azione da controllare
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Classe $classe Classe della lezione
   * @param OsservazioneClasse|OsservazioneAlunno $osservazione Osservazione sugli alunni
   *
   * @return bool Restituisce vero se l'azione è permessa
   */
  public function azioneOsservazione($azione, \DateTime $data, Docente $docente, Classe $classe,
                                      OsservazioneClasse $osservazione=null) {
    if ($azione == 'add') {
      // azione di creazione
      $oggi = new \DateTime();
      if ($data->format('Y-m-d') <= $oggi->format('Y-m-d')) {
        // data non nel futuro
        if (!$osservazione) {
          // ok
          return true;
        }
      }
    } elseif ($azione == 'edit') {
      // azione di modifica
      if ($osservazione) {
        // esiste nota
        if ($docente->getId() == $osservazione->getCattedra()->getDocente()->getId()) {
          // stesso docente: ok
          return true;
        }
      }
    } elseif ($azione == 'delete') {
      // azione di cancellazione
      if ($osservazione) {
        // esiste nota
        if ($docente->getId() == $osservazione->getCattedra()->getDocente()->getId()) {
          // stesso docente: ok
          return true;
        }
      }
    }
    // non consentito
    return false;
  }

  /**
   * Restituisce la lista delle osservazioni sugli alunni per la cattedra indicata.
   *
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function osservazioni(\DateTime $data, Docente $docente, Cattedra $cattedra) {
    // inizializza
    $dati = array();
    $dati['lista'] = array();
    $periodi = $this->infoPeriodi();
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    // legge osservazioni
    if ($cattedra->getTipo() == 'S') {
      // cattedra di sostegno
      $osservazioni = $this->em->getRepository('AppBundle:OsservazioneAlunno')->createQueryBuilder('o')
        ->select('o.id,o.data,o.testo,c.id AS cattedra_id,d.cognome,d.nome,m.nomeBreve')
        ->join('o.cattedra', 'c')
        ->join('c.docente', 'd')
        ->join('c.materia', 'm')
        ->where('o.alunno=:alunno')
        ->orderBy('o.data', 'DESC')
        ->setParameters(['alunno' => $cattedra->getAlunno()])
        ->getQuery()
        ->getArrayResult();
    } else {
      // cattedra non di sostegno
      $osservazioni = $this->em->getRepository('AppBundle:OsservazioneAlunno')->createQueryBuilder('o')
        ->select('o.id,o.data,o.testo,a.id AS alunno_id,a.cognome,a.nome,a.dataNascita,a.bes,a.note')
        ->join('o.alunno', 'a')
        ->where('o.cattedra=:cattedra')
        ->orderBy('o.data', 'DESC')
        ->setParameters(['cattedra' => $cattedra])
        ->getQuery()
        ->getArrayResult();
    }
    // imposta array associativo
    foreach ($osservazioni as $o) {
      $data_oss = $o['data']->format('Y-m-d');
      $periodo = ($data_oss <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
        ($data_oss <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
      $data_str = intval(substr($data_oss, 8)).' '.$mesi[intval(substr($data_oss, 5, 2))];
      $osservazione = $this->em->getRepository('AppBundle:OsservazioneAlunno')->find($o['id']);
      // controlla pulsante edit
      if ($this->azioneOsservazione('edit', $data, $docente, $cattedra->getClasse(), $osservazione)) {
        $edit = $this->router->generate('lezioni_osservazioni_edit', array(
          'cattedra' => $cattedra->getId(), 'data' =>$data_oss, 'id' => $o['id']));
      } else  {
        $edit = null;
      }
      // controlla pulsante delete
      if ($this->azioneOsservazione('delete', $data, $docente, $cattedra->getClasse(), $osservazione)) {
        $delete = $this->router->generate('lezioni_osservazioni_delete', array('id' => $o['id']));
      } else  {
        $delete = null;
      }
      if ($cattedra->getTipo() == 'S') {
        // cattedra di sostegno
        $sostegno = array(
          'id' => $o['id'],
          'data' => $data_str,
          'testo' => $o['testo'],
          'nome' => $o['nomeBreve'].' ('.$o['nome'].' '.$o['cognome'].')',
          'edit' => $edit,
          'delete' => $delete
          );
        if ($o['cattedra_id'] == $cattedra->getId()) {
          // è cattedra di docente
          $dati['cattedra'][$periodo][$data_oss][] = $sostegno;
          if (!isset($dati['lista'][$periodo])) {
            $dati['lista'][$periodo] = array();
          }
        } else {
          // è altra cattedra
          $dati['lista'][$periodo][$o['cattedra_id']][$data_oss][] = $sostegno;
        }
      } else {
        // cattedra non di sostegno
        $dati['lista'][$periodo][$o['alunno_id']][$data_oss][] = array(
          'id' => $o['id'],
          'data' => $data_str,
          'testo' => $o['testo'],
          'nome' => $o['cognome'].' '.$o['nome'].' ('.$o['dataNascita']->format('d/m/Y').')',
          'bes' => $o['bes'],
          'note' => $o['note'],
          'edit' => $edit,
          'delete' => $delete
          );
      }
    }
    // controlla pulsante add
    if ($this->azioneOsservazione('add', $data, $docente, $cattedra->getClasse(), null)) {
      $dati['add'] = $this->router->generate('lezioni_osservazioni_edit', array(
        'cattedra' => $cattedra->getId(), 'data' =>$data->format('Y-m-d')));
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Restituisce la lista delle osservazioni personali per la cattedra indicata.
   *
   * @param \DateTime $data Data della lezione
   * @param Docente $docente Docente della lezione
   * @param Cattedra $cattedra Cattedra del docente
   *
   * @return array Dati restituiti come array associativo
   */
  public function osservazioniPersonali(\DateTime $data, Docente $docente, Cattedra $cattedra) {
    // inizializza
    $dati = array();
    $dati['lista'] = array();
    $periodi = $this->infoPeriodi();
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    // legge osservazioni
    $osservazioni = $this->em->getRepository('AppBundle:OsservazioneClasse')->createQueryBuilder('o')
      ->where('o.cattedra=:cattedra AND o INSTANCE OF AppBundle:OsservazioneClasse')
      ->orderBy('o.data', 'DESC')
      ->setParameters(['cattedra' => $cattedra])
      ->getQuery()
      ->getResult();
    // imposta array associativo
    foreach ($osservazioni as $o) {
      $data_oss = $o->getData()->format('Y-m-d');
      $periodo = ($data_oss <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
        ($data_oss <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
      $data_str = intval(substr($data_oss, 8)).' '.$mesi[intval(substr($data_oss, 5, 2))];
      // controlla pulsante edit
      if ($this->azioneOsservazione('edit', $data, $docente, $cattedra->getClasse(), $o)) {
        $edit = $this->router->generate('lezioni_osservazioni_personali_edit', array(
          'cattedra' => $cattedra->getId(), 'data' =>$data_oss, 'id' => $o->getId()));
      } else  {
        $edit = null;
      }
      // controlla pulsante delete
      if ($this->azioneOsservazione('delete', $data, $docente, $cattedra->getClasse(), $o)) {
        $delete = $this->router->generate('lezioni_osservazioni_personali_delete', array('id' => $o->getId()));
      } else  {
        $delete = null;
      }
      // memorizza dati
      $dati['lista'][$periodo][$data_oss][] = array(
        'id' => $o->getId(),
        'data' => $data_str,
        'testo' => $o->getTesto(),
        'edit' => $edit,
        'delete' => $delete
        );
    }
    // controlla pulsante add
    if ($this->azioneOsservazione('add', $data, $docente, $cattedra->getClasse(), null)) {
      $dati['add'] = $this->router->generate('lezioni_osservazioni_personali_edit', array(
        'cattedra' => $cattedra->getId(), 'data' =>$data->format('Y-m-d')));
    }
    // restituisce dati come array associativo
    return $dati;
  }

  /**
   * Controlla la presenza dei nomi degli alunni nel testo indicato
   *
   * @param \DateTime $data Data della lezione
   * @param Classe $classe Classe da controllarea
   * @param string $testo Testo da controllarea
   *
   * @return null|string Nome trovato o null se non trovato
   */
  public function contieneNomiAlunni(\DateTime $data, Classe $classe, $testo) {
    // recupera alunni di classe
    $lista = $this->alunniInData($data, $classe);
    $alunni = $this->em->getRepository('AppBundle:Alunno')->createQueryBuilder('a')
      ->select('a.cognome,a.nome')
      ->where('a.id IN (:lista)')
      ->setParameters(['lista' => $lista])
      ->getQuery()
      ->getArrayResult();
    // controlla i nomi
    $evitare = array('da', 'de', 'di', 'del', 'dal', 'della', 'la');
    $parole = preg_split('/[^a-zàèéìòù]+/', mb_strtolower($testo), -1, PREG_SPLIT_NO_EMPTY);
    $parole = array_diff($parole, $evitare);
    foreach ($alunni as $a) {
      $nomi = preg_split('/[^a-zàèéìòù]+/', mb_strtolower($a['nome']), -1, PREG_SPLIT_NO_EMPTY);
      foreach ($nomi as $n) {
        if (in_array($n, $parole)) {
          // trovato nome
          return mb_strtoupper($n);
        }
      }
      $nomi = preg_split('/[^a-zàèéìòù]+/', mb_strtolower($a['cognome']), -1, PREG_SPLIT_NO_EMPTY);
      foreach ($nomi as $n) {
        if (in_array($n, $parole)) {
          // trovato cognome
          return mb_strtoupper($n);
        }
      }
    }
    // nessun nome trovato
    return null;
  }

  /**
   * Restituisce i dettagli dei voti per l'alunno indicato.
   *
   * @param Docente $docente Docente della lezione
   * @param Cattedra $cattedra Cattedra del docente
   * @param Alunno $alunno Alunno selezionato
   *
   * @return array Dati restituiti come array associativo
   */
  public function dettagliVoti(Docente $docente, Cattedra $cattedra, Alunno $alunno) {
    $dati = array();
    $dati['lista'] = array();
    $dati['media'] = array();
    $periodi = $this->infoPeriodi();
    $mesi = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    // legge i voti degli degli alunni
    $voti = $this->em->getRepository('AppBundle:Valutazione')->createQueryBuilder('v')
      ->select('v.id,v.tipo,v.argomento,v.visibile,v.voto,v.giudizio,l.data,d.id AS docente_id,d.nome,d.cognome')
      ->join('v.docente', 'd')
      ->join('v.lezione', 'l')
      ->where('v.alunno=:alunno AND l.materia=:materia AND l.classe=:classe')
      ->orderBy('v.tipo,l.data', 'ASC')
      ->setParameters(['alunno' => $alunno, 'materia' => $cattedra->getMateria(),
        'classe' => $cattedra->getClasse()])
      ->getQuery()
      ->getArrayResult();
    // formatta i dati nell'array associativo
    $media = array();
    foreach ($voti as $v) {
      $data = $v['data']->format('Y-m-d');
      $periodo = ($data <= $periodi[1]['fine'] ? $periodi[1]['nome'] :
        ($data <= $periodi[2]['fine'] ? $periodi[2]['nome'] : $periodi[3]['nome']));
      $v['data_str'] = intval(substr($data, 8)).' '.$mesi[intval(substr($data, 5, 2))];
      if ($v['voto'] > 0) {
        $voto_int = intval($v['voto'] + 0.25);
        $voto_dec = $v['voto'] - intval($v['voto']);
        $v['voto_str'] = $voto_int.($voto_dec == 0.25 ? '+' : ($voto_dec == 0.75 ? '-' : ($voto_dec == 0.5 ? '½' : '')));
        if ($v['visibile']) {
          // considera voti visibili per la media
          if (isset($media[$periodo][$v['tipo']])) {
            $media[$periodo][$v['tipo']]['numero']++;
            $media[$periodo][$v['tipo']]['somma'] += $v['voto'];
          } else {
            $media[$periodo][$v['tipo']]['numero'] = 1;
            $media[$periodo][$v['tipo']]['somma'] = $v['voto'];
          }
        }
      }
      if ($v['docente_id'] == $docente->getId()) {
        // voto del docente connesso
        $v['docente_id'] = 0;
      }
      // memorizza dati
      $dati['lista'][$periodo][$v['tipo']][$data][] = $v;
    }
    // calcola medie
    foreach ($media as $periodo=>$mp) {
      $somma = 0;
      $numero = 0;
      foreach ($mp as $tipo=>$m) {
        $dati['media'][$periodo][$tipo] = number_format($m['somma'] / $m['numero'], 2, ',', null);
        $somma += $m['somma'] / $m['numero'];
        $numero++;
      }
      $dati['media'][$periodo]['totale'] = number_format($somma / $numero, 2, ',', null);
    }
    // restituisce dati
    return $dati;
  }

}
