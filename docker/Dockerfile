### Install Debian S.O.
FROM debian:10.7

### System and build environments
ARG DEBIAN_FRONTEND=noninteractive
ENV LANG="it_IT.UTF-8"
ENV COMPOSER_ALLOW_SUPERUSER="1"
WORKDIR /var/www/giuaschool

### Install software
COPY --chown=www-data:www-data . .
RUN \
# Source repositories
  echo "deb http://deb.debian.org/debian/ buster main contrib non-free" > /etc/apt/sources.list && \
  echo "deb http://deb.debian.org/debian-security buster/updates main contrib non-free" >> /etc/apt/sources.list && \
# Update APT source list
  apt-get -qq update && \
# Set locale
  apt-get -yqq install locales && \
  sed -i -e "s/# $LANG/$LANG/" /etc/locale.gen && \
  echo "LANG=$LANG">/etc/default/locale && \
  dpkg-reconfigure --frontend=noninteractive locales && \
  update-locale LANG=$LANG && \
# Install dev tools
  apt-get -yqq install \
  debconf-utils apt-utils lsb-release \
  curl wget unzip rsync git \
  apt-transport-https openssh-client ca-certificates && \
# Install Apache, MariaDB, Composer
  apt-get install -yqq \
  apache2=2.4.* \
  mariadb-common=1:10.3.* mariadb-server=1:10.3.* mariadb-client=1:10.3.* \
  composer=1.* && \
# Install PHP 7.4
  wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg && \
  echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list && \
  apt-get -qq update && \
  apt-get -yqq install \
  php7.4 php7.4-curl php7.4-gd php7.4-intl php7.4-mbstring php7.4-mysql php7.4-readline php7.4-xml php7.4-zip && \
# Install Symfony
  composer -q install --no-progress --prefer-dist --no-suggest --optimize-autoloader --no-plugins --no-scripts

### Configure software
COPY ./docker/apache2-site.conf /etc/apache2/sites-available/000-default.conf
RUN \
  a2enmod rewrite && \
  sed -r -i -e "s/^;date\.timezone\s*=.*$/date.timezone = Europe\/Rome/" /etc/php/7.4/apache2/php.ini && \
  sed -r -i -e "s/;opcache.enable=1.*$/opcache.enable=1/" /etc/php/7.4/apache2/php.ini && \
  sed -r -i -e "s/^;date\.timezone\s*=.*$/date.timezone = Europe\/Rome/" /etc/php/7.4/cli/php.ini && \
  sed -r -i -e "s/^APP_ENV\s*=.*$/APP_ENV=prod/" .env && \
  service mysql start && mysqladmin -u root password 'root' && \
  service apache2 start && \
  php bin/console doctrine:database:create -n -q && \
  php bin/console doctrine:schema:update -f -q && \
  php bin/console doctrine:fixtures:load --group=App -n -q -e dev && \
  chown -R www-data:www-data .

### Configure services
RUN chmod 755 docker/entrypoint.sh
EXPOSE 80
CMD docker/entrypoint.sh

