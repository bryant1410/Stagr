#!/usr/bin/php
<?php

/*
* Script to setup a staging server for Fortrabbit
* Author: Gabriel Manricks
*
* Created to automate the proccess shown in my article written for NetTuts+
*/

//require_once '/opt/stagr/lib/cilex.phar';
set_include_path(get_include_path(). PATH_SEPARATOR. '/opt/stagr/lib'. PATH_SEPARATOR. 'phar:///opt/stagr/lib/cilex.phar');
include_once 'vendor/autoload.php';
spl_autoload_register(function($className) {
    $classFile = preg_replace('~\\\\~', '/', $className). '.php';
    require_once $classFile;
});

$stagr = new \Stagr\Stagr();
$stagr->run();

exit(0);


/**
 * CODE TO BE MIGRATED BELOW THIS LINE
 * -------------------------------------------------
 **/

    //Welcome Message
    echo <<<LOGO

[1m
       ___ _
      / __| |_ __ _ __ _ _ _
      \__ \  _/ _` / _` | '_|
      |___/\__\__,_\__, |_|
                   |___/
[0m
     [31mStaging Enviroment[0m Setup


LOGO;

    //Check if Root
    if($_SERVER['USER'] == "root") {

        $email = getUsersEmail();

        //Get Projects Name
        $fname = '';
        while (empty($fname)) {
            echo "Please enter the [95mProject's Name[0m: ";
            $fname = trim(fgets(STDIN));
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,14}[a-z0-9]$/', $fname)) {
                echo "Error: '$fname' is not a vailid name -> use only a-z, 0-9 and '-'\n\n";
                $fname = '';
            }
        }

        //Setup Apache options
        createSite($fname, $email);

        //Setup Git Repos
        createGit($fname);

        echo "\n[36mStaging Enviroment Setup !!![0m\n\n";

        //Display Info for setting up local computer
        printHostsInfo($fname);
        printGitInfo($fname);
        printMySQLInfo($fname);

        echo "\nYou are now ready to push, Thank you for reading [36mNetTuts+[0m\n\n";
    }
    else{
        echo "  [31mError:[0m this script must be run as [31mroot[0m\n";
    }

    function createSite($name, $email){
        echo "\n\n[33mSite Setup\n––––––––––[0m\n";

        //Create Folder
        echo "Creating Directories ... ";
        foreach (array('/var/www/web/%s/htdocs', '/var/www/web/%s/redir', '/var/fpm/socks/%s', '/var/fpm/prepend/%s') as $tmpl) {
            $dir = sprintf($tmpl, $name);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            chown($dir, "vagrant");
            chgrp($dir, "vagrant");
        }
        echo "[36mOK[0m\n";

        //Create Symlink for PHP-FPM
        echo "Creating Symlink for PHP-FPM ... ";
        if (!is_link("/var/www/web/$name/redir/php")) {
            exec("chdir /var/www/web/$name/redir; ln -s ../htdocs php");
        }
        //symlink("php", "../htdocs");
        echo "[36mOK[0m\n";

        //Create Vhost File
        echo "Creating Site File ... ";
        $sf = fopen("/etc/apache2/sites-available/" . $name, "w");
        $site = getSite($name, $email);
        fwrite($sf, $site);
        fclose($sf);
        echo "[36mOK[0m\n";

        //Symlink Vhost to sites-enabled
        $vhostLink = "/etc/apache2/sites-enabled/$name";
        if (!is_link($vhostLink)) {
            symlink("/etc/apache2/sites-available/" . $name, $vhostLink);
        }

        //Create FPM Config
        echo "Creating FPM Config ... ";
        $ff = fopen("/etc/php5/fpm/pool.d/$name.conf", "w");
        $conf = getFpmConf($name);
        fwrite($ff, $conf);
        fclose($ff);
        echo "[36mOK[0m\n";

        //Create PHP/FPM prepend file
        echo "Creating PHP/FPM Prepend File ... ";
        $pf = fopen("/var/fpm/prepend/$name/prepend.php", "w");
        $prepend = getFpmPrepend($name);
        fwrite($pf, $prepend);
        fclose($pf);
        echo "[36mOK[0m\n";

        //Creating MySQL User and database
        echo "Creating MySQL User and database ... ";
        $dbh = new \PDO('mysql:host=localhost;dbname=mysql', 'root');
        $sth = $dbh->prepare('SHOW DATABASES LIKE ?');
        $sth->bindParam(1, $name);
        $sth->execute();
        if ($sth->rowCount() === 0) {
            echo "X";

            //$name is save cause checked for [a-z0-9-]
            $sth = $dbh->prepare('CREATE DATABASE `'. $name. '`');
            $sth->bindParam(1, $name);
            $sth->execute();
        }

        foreach (array('%', 'localhost') as $host) {
            $sth = $dbh->prepare('INSERT INTO user (Host, User, Password) VALUES (?, ?, PASSWORD(?)) ON DUPLICATE KEY UPDATE Host = Host');
            $sth->bindParam(1, $host);
            $sth->bindParam(2, $name);
            $sth->bindParam(3, $name);
            $sth->execute();

            $sth = $dbh->prepare('INSERT INTO db (Host, Db, User, Select_priv, Insert_priv, Update_priv, Delete_priv, Create_priv, Drop_priv, References_priv, Index_priv, Alter_priv, Create_tmp_table_priv, Lock_tables_priv, Create_view_priv, Show_view_priv) VALUES (?, ?, ?, "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y", "Y") ON DUPLICATE KEY UPDATE Host = Host');
            $sth->bindParam(1, $host);
            $sth->bindParam(2, $name);
            $sth->bindParam(3, $name);
            $sth->execute();
        }
        $dbh->query('FLUSH PRIVILEGES');
        echo "[36mOK[0m\n";

        //Update hosts file
        echo "Update hosts file ... ";
        $hostsFile = '/etc/hosts';
        $hostsOld = preg_grep('/^\s*(?:#|$)/', preg_split('/\n/', file_get_contents($hostsFile)), PREG_GREP_INVERT);
        $hostsNew = array();
        $hostsSeen = false;
        foreach ($hostsOld as $hostLine) {
            list($ip, $hosts) = preg_split('/\s+/', $hostLine, 2);
            if (preg_match('/\b'. $name. '\.mysql\.dev/', $hosts)) {
                $hostsSeen = true;
                break;
            }
        }
        if (!$hostsSeen) {
            $hf = fopen($hostsFile, "a");
            fwrite($hf, "127.0.0.1    $name.mysql.dev\n");
            fclose($hf);
        }
        echo "[36mOK[0m\n";


        //Restart Apache
        echo "Restarting Apache ... ";
        exec("service apache2 restart");
        echo "[36mOK[0m\n";

        //Restart PHP-FPM
        echo "Restarting PHP-FPM ... ";
        exec("service php5-fpm restart");
        echo "[36mOK[0m\n";
    }

    function createGit($name){
        echo "\n\n[33mGit Setup\n–––––––––[0m\n";

        //Create folder for Bare Repo
        echo "Creating Bare Repository ... ";
        $gitDir = "/home/vagrant/" . $name . ".git";
        if (!is_dir($gitDir)) {
            mkdir($gitDir, 0755);
        }
        chown($gitDir, "vagrant");
        chgrp($gitDir, "vagrant");

        //Create Bare Repo
        chdir("/home/vagrant/" . $name . ".git");
        exec("sudo -u vagrant git init --bare");
        echo "[36mOK[0m\n";

        //Create Site's Repo
        echo "Creating Repo in Sites Directory ... ";
        chdir("/var/www/web/" . $name. "/htdocs");
        exec("sudo -u vagrant git init");

        //Link Both Repos
        exec("sudo -u vagrant git remote add origin " . $gitDir);
        echo "[36mOK[0m\n";

        //Add the two hooks (pre-receive & post-receive)
        echo "Adding Hooks ... ";
        $preh = fopen($gitDir . "/hooks/pre-receive", "w");
        $prf = getPreHook();
        fwrite($preh, $prf);
        fclose($preh);

        $posth = fopen($gitDir . "/hooks/post-receive", "w");
        $prf = getPostHook($name);
        fwrite($posth, $prf);
        fclose($posth);

        //Set permission to executable on both hooks
        chmod($gitDir . "/hooks/pre-receive", 0775);
        chown($gitDir . "/hooks/pre-receive", "vagrant");
        chgrp($gitDir . "/hooks/pre-receive", "vagrant");

        chmod($gitDir . "/hooks/post-receive", 0775);
        chown($gitDir . "/hooks/post-receive", "vagrant");
        chgrp($gitDir . "/hooks/post-receive", "vagrant");

        echo "[36mOK[0m\n";

    }

    //Function to display IP address of current VM for hosts purposes
    function printHostsInfo($name){
        echo "To get started, add the correct record to your [95mHOSTS[0m file: ([95m/etc/hosts[0m)\n\n";
        echo getIps($name);
        echo "\nIf you're not sure try accessing the [95mIPs[0m from your computer ([95mbrowser, ping, curl, etc..[0m)\n\n";
    }

    //Helper function to get IP's
    function getIps($name){
        exec("ip -4 -o addr show label eth*", $arr);
        foreach($arr as $k => $ip)
        {
            $arr[$k] = "     " . str_pad(filterIP($ip), 15) . "   " . $name . ".dev\n";
        }
        return implode("", $arr);
    }

    //Helper function to extract IP's from console text
    function filterIP($ipstr){
        $start = strpos($ipstr, "inet") + 5;
        $length = (strpos($ipstr, "/", $start)) - $start;
        return substr($ipstr, $start, $length);
    }

    //Function to display the GIT remote address
    function printGitInfo($name){
        echo "\nAnd add this server to your [95mGIT[0m repository:\n\n";
        echo "     git remote add staging vagrant@" . $name . ".dev:" . $name . ".git\n";
    }

    //Function to display MySQL connection info
    function printMySQLInfo($name) {
        echo "\nTo connect to your MySQL database from your App use:\n\n";
        echo "     Host:     $name.mysql.dev\n";
        echo "     User:     $name\n";
        echo "     Password: $name\n";
    }

    //Function to get the user's email for use in Vhost
    function getUsersEmail(){
        $email = "";

        //Check if first time run
        if(!file_exists("/home/vagrant/.stagedata")){
            echo "This seems to be your first time running this program\n\n";

            //Get Users email and pub key
            echo "Please enter your [95mE-Mail[0m: ";
            $email = trim(fgets(STDIN));
            echo "Please enter your [95mSSH public key[0m: ";
            $pub  = trim(fgets(STDIN));

            //Save info
            echo "\nAdding you as an [33mAuthorized Host[0m\n\n";

            $sdf = fopen("/home/vagrant/.stagedata", "w");
            fwrite($sdf, $email . "\n" . $pub);
            fclose($sdf);

            //Add to Authed Hosts
            $af = fopen("/home/vagrant/.ssh/authorized_keys", "a");
            fwrite($af, $pub);
            fclose($af);
        }
        else{
            //Get users Email from stagedata file for Later
            $sdf = fopen("/home/vagrant/.stagedata", "r");
            $email = fgets($sdf);
            fclose($sdf);
        }
        return $email;
    }

    //function to return Vhost
    function getSite($name, $email){
        return <<<SITE

FastCgiExternalServer /var/www/web/$name/redir/php -socket /var/fpm/socks/$name.sock -idle-timeout 305 -flush

<VirtualHost *:80>
    ServerAdmin $email
    ServerName $name.dev
    DocumentRoot /var/www/web/$name/htdocs

    SetEnv APP_NAME "$name"

    # PHP Settings
    <FilesMatch \.php$>
        SetEnv no-gzip dont-vary
        Options +ExecCGI
    </FilesMatch>
    AddHandler php5-$name-fcgi .php
    Action php5-$name-fcgi /.ctrl/~~~php
    Alias /.ctrl/~~~php /var/www/web/$name/redir/php

    <Directory /var/www/web/$name/htdocs>
        # PathInfo for PHP-FPM
        RewriteEngine On
        RewriteCond %{REQUEST_URI} \.php/ [NC]
        RewriteRule ^(.*)\.php/(.*)$    /$1.php [NC,L,QSA,E=PATH_INFO:/$2]

        Options -All +SymLinksIfOwnerMatch
        AllowOverride AuthConfig Limit FileInfo Indexes Options=SymLinksIfOwnerMatch,MultiViews,Indexes
        Order allow,deny
        allow from all
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/$name.log
    LogLevel debug
</VirtualHost>

SITE;
    }

    //function to return fpm config
    function getFpmConf($name) {
        return <<<FPMCONF
[$name]
listen = /var/fpm/socks/$name.sock

listen.owner = vagrant
listen.group = www-data
listen.mode  = 0660

user = vagrant
group = www-data

pm = dynamic
pm.max_children = 3
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 1000
request_terminate_timeout = 300
php_value[open_basedir] = ""
php_value[include_path] = ".:/usr/share/php:/var/www/web/$name/htdocs"
php_value[upload_tmp_dir] = "/tmp"
php_value[session.save_path] = "/tmp"
php_value[apc.shm_size] = "64M"
php_value[auto_prepend_file] = "/var/fpm/prepend/$name/prepend.php"
php_value[max_execution_time] = "300"
php_value[upload_max_filesize] = "128M"
php_value[default_charset] = "UTF-8"
php_value[short_open_tag] = "ON"
;php_value[date.timezone] = "Europe/Berlin"

FPMCONF;
    }

    //function to return fpm config
    function getFpmPrepend($name) {
        return <<<FPMPREPEND
<?php

if (!defined('__PREPEND_INITED')) {
    define('__PREPEND_INITED', true);

    foreach (array('SCRIPT_NAME', 'PHP_SELF') as \$env) {
        \$_SERVER[\$env] = str_replace('/.ctrl/~~~php/', '/', str_replace('/.ctrl/php/', '/', \$_SERVER[\$env]));
    }
    foreach (array('SCRIPT_FILENAME') as \$env) {
        \$_SERVER[\$env] = str_replace('/.ctrl/~~~php/', '/htdocs/', str_replace('/.ctrl/php/', '/htdocs/', \$_SERVER[\$env]));
    }
    foreach (array_keys(\$_SERVER) as \$env_key) {
        if (\$env_key == 'REDIRECT_STATUS') {
            continue;
        }
        elseif (preg_match('/^(?:REDIRECT_)+(.+)$/', \$env_key, \$match)) {
            if (! isset(\$_SERVER[\$match[1]])) {
                \$_SERVER[\$match[1]] = \$_SERVER[\$env_key];
            }
            unset(\$_SERVER[\$env_key]);
        }
    }
    foreach (array('ORIG_SCRIPT_FILENAME', 'ORIG_PATH_INFO', 'ORIG_PATH_TRANSLATED', 'ORIG_SCRIPT_NAME', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_FOR') as \$key) {
        unset(\$_SERVER[\$key]);
    }
}

FPMPREPEND;
    }

    //function to return pre-receive hook
    function getPreHook(){
        return <<<PREHOOK
#!/usr/bin/php
<?php
echo "[33mStep1: Updating repository[0m \\n";
?>
PREHOOK;
    }

    //function to return post-receive hook
    function getPostHook($name){
        return <<<POSTHOOK
#!/usr/bin/php
<?php

require("/home/vagrant/PHP-GIT-Hooks/PHook.php");
\$ph = new PHook;

\$ph->clear(" -> ")->cyan("OK")->withoutACommand();

\$ph->say("Step2: Deploying")
    ->thenRun(function(){
        \$git = "git --git-dir=/var/www/web/$name/htdocs/.git/ --work-tree=/var/www/web/$name/htdocs/";
        exec("\$git fetch -q origin");
        exec("\$git reset --hard origin/master");
    })
    ->clear("\n -> ")->plain("")->andFinallySay("OK");

\$ph->onTrigger("[trigger:composer]")
    ->say("Step3: Composer Hook")->clear("\n -> Triggering install - get a ")->cyan("coffee")
    ->thenRun(function(){
        chdir("/var/www/web/$name/htdocs");
        putenv("GIT_DIR");
        exec("composer update");
    })
    ->clear("\n -> ")->plain("")->andFinallySay("OK");

\$ph->say(">> All Done <<")->withoutACommand();

POSTHOOK;

    }

?>
