CREATE DATABASE IF NOT EXISTS jaonuea CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jaonuea;

-- 1. Users & Security
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    shipping_address TEXT NULL,
    profile_image VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    auth_provider ENUM('local', 'google', 'line', 'facebook') DEFAULT 'local',
    provider_id VARCHAR(255) NULL,
    role ENUM('admin', 'retail', 'wholesale') NOT NULL DEFAULT 'retail',
    cancel_penalty INT DEFAULT 0,
    reset_token VARCHAR(128) NULL,
    reset_token_code VARCHAR(20) NULL,
    reset_token_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Farm Management
CREATE TABLE IF NOT EXISTS cattles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tag_number VARCHAR(50) NOT NULL UNIQUE,
    seller_name VARCHAR(150) NULL,
    seller_phone VARCHAR(30) NULL,
    breed VARCHAR(50) NOT NULL,
    gender VARCHAR(20) DEFAULT 'ผู้',
    weight_kg DECIMAL(8,2) NOT NULL,
    initial_weight_kg DECIMAL(8,2) NULL,
    cost_price DECIMAL(10,2) NOT NULL,
    status ENUM('alive', 'processed') DEFAULT 'alive',
    purchased_at DATE NOT NULL,
    image_url VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cattle_growth (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cattle_id INT NOT NULL,
    weight_kg DECIMAL(8,2) NOT NULL,
    measured_at DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cattle_id) REFERENCES cattles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cattle_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cattle_id INT NOT NULL,
    record_type ENUM('ฉีดวัคซีน', 'ถ่ายพยาธิ', 'รักษาโรค') NOT NULL,
    record_date DATE NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cattle_id) REFERENCES cattles(id) ON DELETE CASCADE
);

-- 3. Inventory & Products
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    price_retail DECIMAL(10,2) NOT NULL,
    price_wholesale DECIMAL(10,2) NOT NULL,
    stock_kg DECIMAL(8,2) DEFAULT 0.00,
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_processing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cattle_id INT NOT NULL,
    product_id INT NOT NULL,
    yield_kg DECIMAL(8,2) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cattle_id) REFERENCES cattles(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- 4. Order Management
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    final_total_amount DECIMAL(10,2) NULL,
    order_type ENUM('retail', 'wholesale') NOT NULL DEFAULT 'retail',
    delivery_date DATE NULL,
    status ENUM('pending_payment', 'processing', 'awaiting_final_payment', 'verifying_final_payment', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled') DEFAULT 'pending_payment',
    cancel_reason VARCHAR(255) NULL,
    shipping_address TEXT NOT NULL,
    tracking_number VARCHAR(100) NULL,
    pickup_code VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_kg DECIMAL(8,2) NOT NULL,
    actual_quantity_kg DECIMAL(8,2) NULL,
    price_per_kg DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- 5. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    slip_image_url VARCHAR(255) NOT NULL,
    payment_type ENUM('full', 'deposit') NOT NULL DEFAULT 'full',
    total_required DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    remaining_amount DECIMAL(10,2) DEFAULT 0.00,
    remaining_status VARCHAR(50) DEFAULT 'pending',
    second_slip_image_url VARCHAR(255) NULL,
    status VARCHAR(50) DEFAULT 'pending',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

-- 6. User Shipping Addresses (Multi-Address Management)
CREATE TABLE IF NOT EXISTS user_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    province VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    subdistrict VARCHAR(100) NOT NULL,
    zipcode VARCHAR(10) NOT NULL,
    address_detail TEXT NOT NULL,
    label ENUM('home', 'work', 'other') DEFAULT 'home',
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- INITIAL SYSTEM DATA (Admin Account & Product Catalog Only - No Mock Transactions)
-- --------------------------------------------------------

-- Admin Account (รหัสผ่านคือ admin1497)
INSERT INTO users (username, email, first_name, last_name, phone, shipping_address, password_hash, role) VALUES 
('admin', 'admin@example.com', 'ผู้ดูแล', 'ระบบ', '0812345678', 'ฟาร์มจ้าวเนื้อ 1', '$2y$10$yiOOH494DgqveY9wMmjyMeAfV0.35dlToy72HIIEZ1hpXmu9QIpbG', 'admin')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);

-- Product Catalog (เริ่มต้นสต๊อก 0.00 กก.)
INSERT INTO products (name, description, price_retail, price_wholesale, stock_kg, image_url) VALUES
('เนื้อสันคอ', 'เนื้อส่วนคอที่มีไขมันแทรก นุ่ม เหมาะสำหรับปิ้งย่างหรือชาบู', 250.00, 250.00, 0.00, 'assets/img/chuck.jpg'),
('เนื้อเสือร้องไห้', 'เนื้ออกส่วนล่าง มีไขมันติด เหมาะสำหรับการย่าง', 250.00, 250.00, 0.00, 'assets/img/brisket.jpg'),
('เนื้อน่อง', 'เนื้อส่วนน่องที่ต้องตุ๋นเป็นเวลานานจนเปื่อย เหมาะสำหรับทำซุปหรือสตูว์', 250.00, 250.00, 0.00, 'assets/img/shank.jpg'),
('เนื้อสะโพก', 'เนื้อแดงไขมันน้อย เหมาะสำหรับผัด แกง หรือทอด', 250.00, 250.00, 0.00, 'assets/img/rump.jpg'),
('เนื้อสันนอก', 'เนื้อนุ่ม มีขอบมันด้านข้าง เหมาะสำหรับทำสเต็ก', 250.00, 250.00, 0.00, 'assets/img/striploin.jpg'),
('เนื้อสันใน', 'เนื้อนุ่มที่สุด ไขมันน้อย ทานละลายในปาก', 250.00, 250.00, 0.00, 'assets/img/tenderloin.jpg'),
('เนื้อบด', 'เนื้อแดงผสมมันบดละเอียด เหมาะสำหรับทำเบอร์เกอร์หรือผัดกะเพรา', 250.00, 250.00, 0.00, 'assets/img/ground_beef.jpg'),
('กระดูกซี่โครง', 'กระดูกซี่โครงติดเนื้อ สำหรับต้มน้ำซุปให้หวานกลมกล่อม', 200.00, 200.00, 0.00, 'assets/img/ribs.jpg'),
('เครื่องในวัวรวม', 'รวมเครื่องในวัวหลากหลายส่วน สำหรับทำต้มเครื่องในหรือแกงอ่อม', 200.00, 200.00, 0.00, 'assets/img/mixed_offal.jpg')
ON DUPLICATE KEY UPDATE id=id;

