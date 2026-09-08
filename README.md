# Trip Time Attendance — Backend (Laravel)

ระบบลงเวลาเข้า-ออกงานด้วยการสแกน QR Code พร้อม Auth พนักงานผ่าน LINE Login
และระบบหลังบ้านสำหรับ SuperAdmin

## Stack

- Laravel 13 (PHP 8.3)
- Sanctum (API token auth, ใช้ร่วมกันทั้งฝั่ง Employee และ SuperAdmin)
- Laravel Socialite + `socialiteproviders/line` (LINE Login)
- `endroid/qr-code` (สร้างรูป QR Code ของแต่ละจุดสแกน)
- MySQL (production) / SQLite (local dev)

## โครงสร้างข้อมูลหลัก

| ตาราง | คำอธิบาย |
| --- | --- |
| `admins` | บัญชี SuperAdmin (สิทธิ์เดียว, login ด้วย username/password) |
| `employees` | พนักงาน สร้างอัตโนมัติจากการ Login ด้วย LINE ครั้งแรก (role = `member`) |
| `locations` | จุดสแกน QR แต่ละจุด มี `qr_token` ไม่ซ้ำกัน ใช้สร้างรูป QR |
| `attendances` | บันทึกเวลาเข้า/ออกงาน อ้างอิง employee + location |

## การตั้งค่า (Setup)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --tag="sanctum-migrations"
php artisan migrate --seed
```

Seeder จะสร้างบัญชี SuperAdmin เริ่มต้น:

- username: `superadmin`
- password: `ChangeMe123!` — **ต้องเปลี่ยนทันทีหลัง deploy จริง** (ยังไม่มีหน้าจอเปลี่ยนรหัสผ่านในตัว
  ให้แก้ผ่าน `php artisan tinker` หรือเพิ่ม endpoint เปลี่ยนรหัสภายหลัง)

## ตัวแปรแวดล้อมสำคัญ (`.env`)

```env
APP_URL=http://localhost:1000
FRONTEND_URL=http://localhost:1001      # ใช้สร้างลิงก์ redirect กลับ Next.js และ CORS

DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=tipose_emp
DB_USERNAME=tipose_emp
DB_PASSWORD=...

LINE_CLIENT_ID=...
LINE_CLIENT_SECRET=...
LINE_REDIRECT_URI=http://localhost:1000/api/auth/line/callback
```

### ⚠️ สถานะฐานข้อมูลจริง (MySQL) ที่ให้มา

ทดสอบเชื่อมต่อแล้วได้ error:

```
SQLSTATE[HY000] [1044] Access denied for user 'tipose_emp'@'%' to database 'tipose_emp'
```

หมายความว่า **username/password ถูกต้อง แต่ user ยังไม่มีสิทธิ์ (GRANT) บนฐานข้อมูลนี้** ต้องแก้ที่ฝั่ง
เซิร์ฟเวอร์ MySQL (เช่นผ่าน phpMyAdmin/cPanel) ด้วยคำสั่ง:

```sql
GRANT ALL PRIVILEGES ON tipose_emp.* TO 'tipose_emp'@'%';
FLUSH PRIVILEGES;
```

ระหว่างนี้ในเครื่อง dev ใช้ SQLite แทนชั่วคราว (`DB_CONNECTION=sqlite`) — โครงสร้างตารางเหมือนกันทุก
ประการ พอแก้สิทธิ์ที่ฝั่ง MySQL เสร็จ ให้สลับ `.env` กลับไปใช้ค่า mysql ที่ comment ไว้แล้วรัน
`php artisan migrate --seed` ได้ทันที

### ตั้งค่า LINE Login

1. สร้าง Channel ประเภท "LINE Login" ที่ [LINE Developers Console](https://developers.line.biz/console/)
2. ตั้งค่า Callback URL เป็น `{APP_URL}/api/auth/line/callback`
3. นำ Channel ID / Channel Secret มาใส่ `LINE_CLIENT_ID` / `LINE_CLIENT_SECRET`

## รันเซิร์ฟเวอร์

```bash
php artisan serve --port=1000
```

## API หลัก (`/api`)

| Method | Path | Auth | คำอธิบาย |
| --- | --- | --- | --- |
| GET | `/auth/line/redirect` | - | เริ่ม LINE Login |
| GET | `/auth/line/callback` | - | LINE callback → ออก Sanctum token → redirect ไป frontend |
| GET | `/me` | employee | ข้อมูลพนักงานที่ login |
| POST | `/attendance/scan` | employee | สแกน QR (`qr_token`) เพื่อลงเวลา สลับ check-in/out อัตโนมัติ |
| GET | `/attendance/history` | employee | ประวัติลงเวลาของตนเอง (`?month=YYYY-MM`) |
| GET | `/locations/{id}/qr` | - (public) | รูปภาพ QR Code (PNG) ของจุดสแกน |
| POST | `/admin/login` | - | Login SuperAdmin |
| GET/PATCH/DELETE | `/admin/employees` | admin | จัดการพนักงาน (เปิด/ปิดการใช้งาน) |
| GET/POST/PATCH/DELETE | `/admin/locations` | admin | จัดการจุดสแกน QR |
| POST | `/admin/locations/{id}/regenerate-token` | admin | สร้าง QR ใหม่ (QR เดิมใช้ไม่ได้) |
| GET | `/admin/reports/daily` | admin | รายงานประจำวัน (`?date=YYYY-MM-DD`) |
| GET | `/admin/reports/monthly` | admin | รายงานประจำเดือน (`?month=YYYY-MM`) |
