# PES College Portal (Major Project)

A unified college management web portal built with PHP and MySQL. It features role-based access control for Students, Teachers, Principals, and Admins to manage attendance, fees, exams, and student records.

---

## 🛠 Local Setup (XAMPP)

1. Place the project folder into your XAMPP web root:
   ```text
   C:\xampp\htdocs\pes-portal\
   ```
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin/`, create a database named `pes_college_portal`, and import `database.sql`.
4. Visit `http://localhost/pes-portal/` in your browser.

---

## 🚀 Deploying to Vercel

This repository is pre-configured with `vercel.json` and a serverless entry router (`api/index.php`) using the `vercel-php` runtime.

### Step 1: Set up a Free Cloud MySQL Database
Because Vercel runs stateless serverless functions, you need a remote MySQL database:
- **TiDB Cloud** (Free tier MySQL-compatible): [https://tidbcloud.com/](https://tidbcloud.com/)
- **Aiven MySQL** (Free tier): [https://aiven.io/](https://aiven.io/)
- **Clever Cloud MySQL** (Free tier): [https://www.clever-cloud.com/](https://www.clever-cloud.com/)

Import the schema and initial data from `database.sql` into your cloud database.

### Step 2: Deploy on Vercel
1. Go to [Vercel](https://vercel.com/) and click **"Add New..."** > **"Project"**.
2. Select your repository: `rishudr0903-rgb/major-project`.
3. Under **Environment Variables**, add:
   - `DB_HOST`: Your remote MySQL hostname (e.g. `gateway01.us-east-1.prod.aws.tidbcloud.com`)
   - `DB_NAME`: `pes_college_portal` (or your cloud DB name)
   - `DB_USER`: Your cloud database username
   - `DB_PASS`: Your cloud database password
   - `DB_PORT`: `3306` (or your provider's port, e.g. `4000` for TiDB)
   - `DB_SSL`: `true` (if your cloud provider requires SSL)
4. Click **Deploy**.
