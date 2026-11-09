#!/bin/sh
# Installs OpenCart from the official release zip (mounted at /opencart.zip) with OpenCart's own command-line
# installer, then runs Apache. The store answers on port 80 and on $OC_PORT (the port the host uses).
set -e
WEB=/var/www/html

if [ ! -f "$WEB/.oc-installed" ]; then
  echo "[oc] extracting /opencart.zip"
  rm -rf /tmp/oc && mkdir -p /tmp/oc
  php /scripts/extract.php /opencart.zip /tmp/oc
  UPLOAD=$(find /tmp/oc -maxdepth 3 -type d -name upload | head -1)
  [ -n "$UPLOAD" ] || { echo "[oc] no upload/ folder in the zip"; exit 1; }
  find "$WEB" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  cp -a "$UPLOAD"/. "$WEB"/
  rm -rf /tmp/oc
  cp "$WEB/config-dist.php" "$WEB/config.php"
  cp "$WEB/admin/config-dist.php" "$WEB/admin/config.php"
  chown -R www-data:www-data "$WEB"

  echo "[oc] waiting for the database"
  i=0
  until php -r 'exit(@mysqli_connect("db", "root", "opencart", "opencart") ? 0 : 1);'; do
    i=$((i + 1)); [ $i -gt 60 ] && { echo "[oc] no database"; exit 1; }
    sleep 1
  done

  echo "[oc] installing"
  cd "$WEB/install"
  su -s /bin/sh www-data -c "php cli_install.php install \
    --db_hostname db --db_username root --db_password opencart --db_database opencart \
    --db_driver mysqli --db_port 3306 --db_prefix oc_ \
    --username '$OC_ADMIN_USER' --password '$OC_ADMIN_PASSWORD' --email '$OC_ADMIN_EMAIL' \
    --http_server 'http://localhost:$OC_PORT/'"
  cd /
  rm -rf "$WEB/install"
  touch "$WEB/.oc-installed"
  echo "[oc] installed"
fi

# Apache on port 80 and on the host's port.
grep -q "^Listen $OC_PORT\$" /etc/apache2/ports.conf || echo "Listen $OC_PORT" >> /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:80 *:$OC_PORT>/" /etc/apache2/sites-available/000-default.conf
a2enmod rewrite >/dev/null 2>&1 || true
# Errors go to the log, never into a page (an AJAX answer must stay JSON).
printf 'display_errors=Off\nlog_errors=On\nerror_reporting=E_ALL\nmemory_limit=256M\nupload_max_filesize=16M\npost_max_size=16M\n' > /usr/local/etc/php/conf.d/zz-opencart-tests.ini
echo "[oc] ready on port $OC_PORT"
exec apache2-foreground
