PETRA MUELLER — files for your Windows XAMPP

CONTENTS
--------
1) petra-mueller-wordpress-folder.zip
   = full WordPress site folder

2) wp_petra_mueller.sql
   = MySQL database dump (import in phpMyAdmin)

3) 01-create-database.sql
   = optional: creates empty DB first


WHAT TO DO
----------
A) WordPress folder
   1. Extract petra-mueller-wordpress-folder.zip
   2. You will get a folder named: petra-mueller
   3. Copy it to:  D:\xampp\htdocs\petra-mueller

B) Database (phpMyAdmin)
   1. Open http://localhost:8082/phpmyadmin/
   2. Import wp_petra_mueller.sql
      (it creates database wp_petra_mueller automatically)
   OR: run 01-create-database.sql first, then import wp_petra_mueller.sql

C) Open site
   http://localhost:8082/petra-mueller/

Admin: nimesh / nimesh@123

wp-config is already set for XAMPP root / empty password.
