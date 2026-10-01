# TutorRush Deployment

This folder is a standalone TutorRush application. Upload the folder contents to the directory that should serve the app, then open `index.html`.

## Hosting requirements

- PHP 8.1 or newer
- PHP extensions: `pdo_sqlite` and `mbstring`
- HTTPS enabled for production
- Write access for PHP on the `storage` folder
- Apache with `.htaccess` support, or an equivalent server rule that blocks all web access to `storage/` and `*.sqlite` files

## First launch

1. Upload all files and folders in this directory, including the hidden `.htaccess` files.
2. Set the `storage` directory permission so the web-server user can create files there. On typical shared hosting, directory mode `755` is sufficient; use `775` only if your host requires it.
3. Visit the deployed site and create an account or sign in.
4. The SQLite database is created automatically at `storage/tutorrush.sqlite` on the first request.

## Administrator account

`diyoradaminovaz.m@gmail.com` is automatically assigned the `admin` role. Sign in with that email to view the Tutor Portal's Operations panel.

## Security notes

- Do not upload a development `tutorrush.sqlite` database to a public site unless you intend to migrate its accounts and data.
- Keep `storage/` inaccessible from the web. For Nginx, configure `location ^~ /storage/ { deny all; }`.
- Confirm the site uses HTTPS before inviting users; secure session cookies are enabled automatically over HTTPS.