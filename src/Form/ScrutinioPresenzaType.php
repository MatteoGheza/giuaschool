<?php
/**
 * giua@school
 *
 * Copyright (c) 2017-2019 Antonello Dessì
 *
 * @author    Antonello Dessì
 * @license   http://www.gnu.org/licenses/agpl.html AGPL
 * @copyright Antonello Dessì 2017-2019
 */


namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;


/**
 * ScrutinioPresenzaType - form per la classe ScrutinioPresenza
 */
class ScrutinioPresenzaType extends AbstractType {

  /**
   * Crea il form
   *
   * @param FormBuilderInterface $builder Gestore per la creazione del form
   * @param array $options Lista di opzioni per il form
   */
  public function buildForm(FormBuilderInterface $builder, array $options) {
    // aggiunge campi al form
    $builder
      ->add('docente', HiddenType::class)
      ->add('presenza', ChoiceType::class, array('label' => false,
        'choices' => ['label.scrutinio_presente' => true, 'label.scrutinio_assente' => false],
        'expanded' => true,
        'multiple' => false,
        'label_attr' => ['class' => 'radio-inline gs-pt-0 gs-mr-5'],
        'required' => true))
      ->add('sessoSostituto', ChoiceType::class, array('label' => false,
        'choices' => ['label.prof_M' => 'M', 'label.prof_F' => 'F'],
        'expanded' => false,
        'multiple' => false,
        'required' => true))
      ->add('sostituto', TextType::class, array(
        'label' => 'label.scrutinio_sostituto',
        'trim' => true,
        'required' => false));
  }

  /**
   * Configura le opzioni usate nel form
   *
   * @param OptionsResolver $resolver Gestore delle opzioni
   */
  public function configureOptions(OptionsResolver $resolver) {
    $resolver->setDefaults(array('data_class' => ScrutinioPresenza::class));
  }

}
