# Die Design Checklist Approval System

ระบบ Checklist ตรวจสอบแบบแม่พิมพ์ (Die Design) ก่อนอนุมัติจ่ายแบบ
สำหรับโรงงานผลิตแม่พิมพ์ Extrusion (Solid Die และ Hollow Die)

## Tech Stack

- Backend: PHP 8.x Vanilla (ไม่ใช้ Framework, ไม่ใช้ Composer)
- Database: MySQL
- CSS: Tailwind CSS v3 (CDN)
- JavaScript: Alpine.js v3 (CDN)
- Charts: Chart.js v4 (CDN)
- Tables: DataTables v1.13 (CDN)
- PDF export: [FPDF](http://www.fpdf.org) — single-file PHP library, vendored directly under
  `vendor/fpdf/` (ไม่ใช่ผ่าน Composer) พร้อมฟอนต์ไทย Sarabun (SIL Open Font License) ที่แปลงเป็น
  cp874 encoding ไว้แล้วใน `vendor/fpdf/font/`
- Dev environment: XAMPP

## โครงสร้างโฟลเดอร์

```
/
├── index.php              จุดเข้าเว็บหลัก / login
├── db/                     schema.sql, seed.sql
├── includes/               config.php (ไม่ commit), config.example.php, db.php, auth/lang/
│                           checklist/report/pdf helpers ที่ใช้ร่วมกัน
├── assets/                 css/js เพิ่มเติม และไฟล์ที่อัปโหลด (images, pdf)
├── vendor/fpdf/            FPDF (single-file PDF library) + ฟอนต์ไทย Sarabun — ไม่ใช้ Composer
├── admin/                  หน้าเฉพาะ Admin
├── engineer/               หน้าเฉพาะ Engineer
├── manager/                หน้าเฉพาะ Manager
└── api/                    endpoint สำหรับเรียกผ่าน Alpine.js/fetch
```

## วิธี Setup บน XAMPP

### 1. วางไฟล์โปรเจกต์

โปรเจกต์นี้อยู่ที่ `C:\xampp\htdocs\Checklist_Design` แล้ว (เข้าผ่าน `http://localhost/Checklist_Design`)

### 2. เปิด Apache และ MySQL

เปิด XAMPP Control Panel แล้ว Start service `Apache` และ `MySQL`

### 3. สร้างฐานข้อมูลผ่าน phpMyAdmin

1. เปิดเบราว์เซอร์ไปที่ `http://localhost/phpmyadmin`
2. ไปที่แท็บ **Import**
3. Import ไฟล์ `db/schema.sql` ก่อน (ไฟล์นี้จะสร้างฐานข้อมูลชื่อ `checklist_design` และตารางทั้งหมดให้อัตโนมัติ)
4. Import ไฟล์ `db/seed.sql` ต่อ (ข้อมูลตัวอย่าง: user admin, master data, checklist categories/items)

> หรือ import ผ่าน command line:
> ```
> mysql -u root -p < db/schema.sql
> mysql -u root -p checklist_design < db/seed.sql
> ```

### 4. ตั้งค่า config.php

คัดลอก `includes/.env.example` เป็น `includes/.env` แล้วตั้งค่าเชื่อมต่อฐานข้อมูล:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` — ถ้าไม่ตั้งจะใช้ค่าสำรองของ XAMPP local (`localhost:3306` / `root` / ไม่มีรหัสผ่าน)

จากนั้นเปิดไฟล์ `includes/config.php` แล้วตรวจสอบ/ปรับค่าต่อไปนี้ให้ตรงกับเครื่อง:

- `APP_BASE_URL` — path ที่เข้าเว็บจริง (ปรับตอน deploy ขึ้น shared hosting)
- `APP_SECRET_KEY` — เปลี่ยนเป็นค่าสุ่มก่อนขึ้น production

`config.php` เป็นจุดเดียวที่อ่านค่า config ของทั้งระบบ (รวมค่า DB จาก `.env`) ห้ามเพิ่ม config การเชื่อมต่อ DB ไว้ที่ไฟล์อื่น

### 5. เข้าใช้งาน

เปิด `http://localhost/Checklist_Design`

Login เริ่มต้น (สร้างจาก seed.sql):

- Username: `admin`
- Password: `admin123`

> Phase นี้ยังไม่มีหน้า login/business logic ให้ใช้งานจริง — มีเฉพาะโครงสร้างไฟล์และฐานข้อมูล

## ย้ายฐานข้อมูลไปเครื่องอื่น (Export / Import ผ่าน phpMyAdmin)

บนเครื่องต้นทาง (ทีละคำสั่ง):

```
cd C:\xampp\htdocs\Checklist_Design
C:\xampp\php\php.exe db\export_database.php
```

ได้ไฟล์ `db\backup\checklist_design_YYYYMMDD_HHMMSS.sql` (โครงสร้าง + ข้อมูลทั้งหมด) จากนั้นบนเครื่องปลายทาง:

1. เปิด `http://localhost/phpmyadmin` > แท็บ **Import** (จากหน้าแรก ไม่ต้องเลือกฐานข้อมูล) > เลือกไฟล์ `.sql` > Import
2. คัดลอกโฟลเดอร์ `assets\uploads` (รูป/PDF) ไปด้วย เพราะไม่ได้อยู่ในฐานข้อมูล
3. ตั้งค่า `includes\config.php` และ `includes\.env` ให้ตรงกับเครื่องปลายทาง (ไม่คัดลอกจากเครื่องต้นทางทับ)

ตัวเลือกเพิ่มเติม: `--structure-only` (ฐานข้อมูลเปล่า), `--exclude-data=a,b` (ข้ามข้อมูลบางตาราง),
`--target-db=NAME` (เปลี่ยนชื่อฐานข้อมูลในไฟล์), `--no-create-db` (shared hosting), `--gzip` (ไฟล์ .sql.gz) — ดูรายละเอียดที่หัวไฟล์ `db/export_database.php`
ไฟล์ export มีข้อมูลผู้ใช้ (password hash) ห้าม commit และควรลบทิ้งเมื่อใช้เสร็จ

## การ Deploy ขึ้น Shared Hosting ผ่าน FTP

ระบบนี้เป็น PHP Vanilla ล้วน ไม่ใช้ Composer/Framework จึงสามารถอัปโหลดไฟล์ทั้งโฟลเดอร์ขึ้น
shared hosting ทั่วไป (ที่รองรับ PHP 8.x + MySQL) ได้โดยตรงผ่าน FTP โดยไม่ต้องรัน build step ใด ๆ

### 1. สร้างฐานข้อมูล MySQL บน hosting ผ่าน cPanel

1. เข้า cPanel ของ hosting แล้วเปิดเมนู **MySQL® Databases**
2. สร้างฐานข้อมูลใหม่ (เช่น `youruser_checklist`) — จดชื่อฐานข้อมูลไว้
3. สร้างผู้ใช้ MySQL ใหม่ (เช่น `youruser_dbadmin`) พร้อมตั้งรหัสผ่านที่ปลอดภัย
4. เพิ่มผู้ใช้เข้าฐานข้อมูล (Add User to Database) แล้วให้สิทธิ์ **ALL PRIVILEGES**
5. จดค่าทั้งหมดไว้: ชื่อฐานข้อมูล, username, password, และ DB host (ส่วนใหญ่ hosting ใช้ `localhost`
   แต่บาง hosting อาจให้ host แยกต่างหาก — ตรวจสอบในหน้า cPanel)

### 2. Import schema.sql และ seed.sql

ผ่าน **phpMyAdmin** บน cPanel:

1. เข้า phpMyAdmin แล้วเลือกฐานข้อมูลที่สร้างไว้ในขั้นตอนที่ 1
2. ไปที่แท็บ **Import** แล้วเลือกไฟล์ `db/schema.sql` ก่อน (สร้างตารางทั้งหมด)
   > หมายเหตุ: `schema.sql` มีคำสั่ง `CREATE DATABASE` อยู่ด้วย — ถ้า hosting ไม่อนุญาตให้ user
   > สิทธิ์ `CREATE DATABASE` (ส่วนใหญ่ shared hosting จะไม่ให้) ให้ลบ/ข้ามบรรทัด
   > `CREATE DATABASE IF NOT EXISTS ...` และ `USE checklist_design;` ออกก่อน import
   > เนื่องจากฐานข้อมูลถูกสร้างไว้แล้วในขั้นตอนที่ 1 และ phpMyAdmin จะ import ลงฐานข้อมูล
   > ที่เลือกไว้อยู่แล้วโดยอัตโนมัติ
3. Import ไฟล์ `db/seed.sql` ต่อ (ข้อมูลตัวอย่าง/ผู้ใช้ admin เริ่มต้น) — หรือข้ามได้ถ้าต้องการ
   สร้างผู้ใช้ admin เองผ่าน SQL โดยตรง (ควรเปลี่ยนรหัสผ่าน admin เริ่มต้นทันทีหลัง deploy จริง)

หรือ import ผ่าน command line ถ้า hosting มี SSH access:
```
mysql -u youruser_dbadmin -p youruser_checklist < db/schema.sql
mysql -u youruser_dbadmin -p youruser_checklist < db/seed.sql
```

### 3. ตั้งค่า includes/config.php

โปรเจกต์นี้ commit เก็บเฉพาะ `includes/config.example.php` (ไม่มีรหัสผ่านจริง) ส่วน
`includes/config.php` ที่มีค่าจริงจะไม่ถูก commit — ให้ทำตามนี้บนเครื่อง/hosting แต่ละที่:

1. คัดลอก `includes/config.example.php` เป็น `includes/config.php`
2. คัดลอก `includes/.env.example` เป็น `includes/.env` แล้วใส่ `DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, `DB_PASS` ตามค่าที่ได้จากขั้นตอนที่ 1 (ห้ามอัปโหลด `.env` ของเครื่อง dev ขึ้นไปทับ)
3. แก้ค่าต่อไปนี้ใน `config.php` ให้ตรงกับ hosting จริง:
   - `APP_BASE_URL` — โดเมนจริงที่เข้าเว็บ (เช่น `https://yourdomain.com` หรือ
     `https://yourdomain.com/checklist` ถ้าติดตั้งในโฟลเดอร์ย่อย) **ห้ามมี `/` ปิดท้าย**
   - `APP_SECRET_KEY` — เปลี่ยนเป็นสตริงสุ่มที่ไม่ซ้ำใคร (รันคำสั่ง
     `php -r "echo bin2hex(random_bytes(32));"` แล้ว copy ผลลัพธ์มาใส่)
   - `APP_DEBUG` — **ต้องตั้งเป็น `false` บน production เสมอ** เพื่อไม่ให้ error/stack trace
     หลุดออกไปแสดงบนหน้าเว็บสาธารณะ
4. ค่าคงที่ที่เกี่ยวกับ path/URL อัปโหลดไฟล์ (`UPLOAD_PATH_*`, `UPLOAD_URL_*`) คำนวณจาก
   `ROOT_PATH`/`APP_BASE_URL` โดยอัตโนมัติ ไม่ต้องแก้ และทำงานได้ทั้ง Windows/XAMPP กับ
   Linux shared hosting โดยไม่ต้องเปลี่ยนโค้ด (ทุกไฟล์ในระบบอ่าน path ผ่านค่าคงที่เหล่านี้เท่านั้น
   ไม่มีการ hardcode path แบบ absolute หรือ path แบบ Windows backslash ไว้ที่อื่น)

### 4. อัปโหลดไฟล์ผ่าน FTP

1. เชื่อมต่อ FTP/SFTP ไปยัง hosting ด้วยโปรแกรมเช่น FileZilla
2. อัปโหลดไฟล์/โฟลเดอร์ทั้งหมดของโปรเจกต์ไปไว้ใต้ document root ของ hosting (มักเป็น `public_html/`
   หรือโฟลเดอร์ย่อยถ้าติดตั้งใน subdirectory)
3. **อย่าอัปโหลด** `includes/config.php` ที่มีรหัสผ่านจริงของเครื่อง dev/local ขึ้นไปทับ — ให้สร้าง
   `config.php` ใหม่บน hosting ตามขั้นตอนที่ 3 แทน (หรืออัปโหลดแล้วแก้ไขค่าให้ถูกต้องทันที)
4. ตรวจสอบว่าโฟลเดอร์ `assets/uploads/images/` และ `assets/uploads/pdf/` ถูกอัปโหลดขึ้นไปด้วย
   (โฟลเดอร์ว่างบางโปรแกรม FTP อาจไม่อัปโหลดให้อัตโนมัติ — ถ้าไม่มีให้สร้างโฟลเดอร์เปล่าไว้เอง)

### 5. ตั้งค่า permission ของโฟลเดอร์ assets/uploads/

โฟลเดอร์ที่เว็บต้องเขียนไฟล์ได้ (รูปภาพและ PDF ที่อัปโหลด) ต้องมี permission ให้ web server
เขียนไฟล์ได้:

1. ผ่าน FTP client (เช่น FileZilla) คลิกขวาที่โฟลเดอร์ `assets/uploads/images/` และ
   `assets/uploads/pdf/` เลือก **File permissions**
2. ตั้งค่าเป็น **755** ก่อน (Owner: read/write/execute, Group/World: read/execute) — เพียงพอ
   สำหรับ hosting ส่วนใหญ่ที่รัน PHP ในนาม user เดียวกับเจ้าของไฟล์ (เช่น cPanel ทั่วไป)
3. ถ้าอัปโหลดไฟล์แล้วเจอ error "upload_failed" หรือ "Permission denied" ให้ลองปรับเป็น **775**
   หรือสอบถามฝ่าย support ของ hosting ว่า PHP รันในนาม user ใด (บาง hosting ที่ใช้
   suPHP/PHP-FPM แยก user จำเป็นต้องปรับ permission ต่างจากปกติ)
4. ห้ามตั้งเป็น **777** (เขียน/อ่าน/รันได้จากทุกคน) เว้นแต่จำเป็นจริง ๆ เพราะเป็นความเสี่ยงด้านความปลอดภัย

### 6. ตรวจสอบหลัง Deploy

- เข้าเว็บผ่านโดเมนจริง ทดสอบ login ด้วยบัญชี admin (แล้วเปลี่ยนรหัสผ่านทันทีถ้าใช้ค่าจาก seed.sql)
- ทดสอบสร้าง checklist 1 ใบพร้อมแนบรูป/PDF เพื่อยืนยันว่า `assets/uploads/` เขียนไฟล์ได้จริง
- ทดสอบ Export PDF จากหน้า checklist เพื่อยืนยันว่า `vendor/fpdf/` ทำงานได้ปกติบน hosting
- ตรวจสอบว่า `APP_DEBUG` เป็น `false` และหน้าเว็บไม่แสดง error/stack trace ใด ๆ ออกมา
