# i-AdminConnect • Official School Portal & Document Tracking System

> **A Web-Based Administrative Services and Records Tracking System** designed to streamline institutional workflow, document archiving, and student credential requests.

---

## 📌 Project Overview
**i-AdminConnect** modernizes school document processing (such as Form 137, Good Moral Certificates, Certificates of Enrollment, and Certificates of Employment) through a centralized, paperless, and real-time tracking architecture.

### ✨ Key Features
- **Public Institutional Landing Page**:
  - School Information & Interactive Campus Guide
  - Executive Leadership Messages (School Principal & Assistant Principal)
  - Real-Time Circulars & Bulletin Announcements (`config/announcements.json`)
  - Step-by-Step Request Guidelines & FAQs
- **Multi-Role Portal Access**:
  - **Student**: Submit credential requests, attach requirements, and track progress 24/7 in Philippine Standard Time.
  - **Teacher**: Request academic and employment documents; evaluated directly by the Administrator.
  - **Registrar**: Review student applications, update 4-stage statuses (Submitted, Under Review, Processing, Ready for Release), and record document releases.
  - **Administrator**: Oversee institutional requests, manage user credentials, monitor analytics, and generate reports.
- **Security & Integrity**:
  - Strict role-based routing and session control.
  - Detailed administrative audit logging.
  - 100% responsive interface across desktop, tablet, and mobile devices.

---

## 🛠️ Technology Stack
- **Backend**: PHP 8.x
- **Database**: MySQL / MariaDB (XAMPP)
- **Frontend**: HTML5, CSS3 (Glassmorphism theme), Vanilla JavaScript (IntersectionObserver, hardware-accelerated animations)
- **Styling**: Responsive custom design system (no heavy external CSS framework required)

---

## 👥 Project Development Team
- **Enrico C. Capistrano** — Research Author
- **Edwin E. Cruz** — Research Author
- **Erginia Fatima G. Diansuy** — Research Author

---

## 🚀 Local Setup & Installation
1. Install **[XAMPP](https://www.apachefriends.org/)** on your computer.
2. Clone or extract this repository into your XAMPP web root:
   ```bash
   C:\xampp\htdocs\i-adminconnect
   ```
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Import the database schema via **phpMyAdmin** (`http://localhost/phpmyadmin/`).
5. Open your browser and navigate to:
   ```
   http://localhost/i-adminconnect/
   ```

---

*Developed for Taguig Science High School • Department of Education.*
