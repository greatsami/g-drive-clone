<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

## Google Drive clone

This project built on Laravel 10, Inertia with vue. cloning Google Drive functionality.

- Create/Delete folders to S3 bucket.
- Upload Folders from computer to S3 bucket.
- Upload Files from computer to S3 bucket.
- Share Folders/Files with another registered account (By writing email address).
- List Share Folders/Files with others.
- List Shared Folders/Files by others.
- List Trashed Folders/Files.
- Empty deleted folders/files.

To run the application you need to clone the repo to your local machine, and run it via:
```bash
sail up -d
sail composer update
sail npm install
sail npm run dev
```

copy .env.example to .env

Don't forget to crate S3 bucket and update .env file

and enjoy
