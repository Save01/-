-- ===================================================
-- ฐานข้อมูลร้านพรชัยแบตเตอรี่ บางพลี
-- ===================================================

USE pornchai_battery;

-- ตารางผู้ใช้/สมาชิก
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(200) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(200),
    address TEXT,
    role ENUM('admin','staff','customer') DEFAULT 'customer',
    profile_image VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ยี่ห้อรถ
CREATE TABLE IF NOT EXISTS car_brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('car','motorcycle','both') DEFAULT 'car',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ข้อมูลรถของสมาชิก
CREATE TABLE IF NOT EXISTS member_vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    brand_id INT NOT NULL,
    model VARCHAR(200) NOT NULL,
    license_plate VARCHAR(50) NOT NULL,
    vehicle_type ENUM('car','motorcycle') DEFAULT 'car',
    year VARCHAR(4),
    color VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (brand_id) REFERENCES car_brands(id)
) ENGINE=InnoDB;

-- หมวดหมู่สินค้า
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ยี่ห้อแบตเตอรี่
CREATE TABLE IF NOT EXISTS battery_brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    logo VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- สินค้า (แบตเตอรี่)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    battery_brand_id INT,
    name VARCHAR(300) NOT NULL,
    sku VARCHAR(100) UNIQUE,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    min_quantity INT NOT NULL DEFAULT 5,
    image VARCHAR(500),
    warranty_months INT DEFAULT 12,
    vehicle_type ENUM('car','motorcycle','both') DEFAULT 'both',
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (battery_brand_id) REFERENCES battery_brands(id)
) ENGINE=InnoDB;

-- ความเข้ากันได้ของสินค้ากับรถ
CREATE TABLE IF NOT EXISTS product_compatibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    car_brand_id INT NOT NULL,
    car_model VARCHAR(200),
    year_from VARCHAR(4),
    year_to VARCHAR(4),
    notes TEXT,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (car_brand_id) REFERENCES car_brands(id)
) ENGINE=InnoDB;

-- สินค้าแนะนำ
CREATE TABLE IF NOT EXISTS recommended_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    car_brand_id INT NOT NULL,
    car_model VARCHAR(200),
    priority INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (car_brand_id) REFERENCES car_brands(id)
) ENGINE=InnoDB;

-- การรับสินค้าเข้าสต๊อก
CREATE TABLE IF NOT EXISTS stock_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    cost_per_unit DECIMAL(10,2),
    supplier VARCHAR(300),
    received_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- การขายสินค้า
CREATE TABLE IF NOT EXISTS stock_out (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    sale_price DECIMAL(10,2),
    customer_id INT,
    sold_date DATE NOT NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ข้อมูลประกันสินค้า
CREATE TABLE IF NOT EXISTS warranties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    vehicle_id INT,
    serial_number VARCHAR(200),
    purchase_date DATE NOT NULL,
    warranty_end_date DATE NOT NULL,
    warranty_months INT DEFAULT 12,
    status ENUM('active','expired','claimed') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES member_vehicles(id)
) ENGINE=InnoDB;

-- การเคลมสินค้า
CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warranty_id INT NOT NULL,
    customer_id INT NOT NULL,
    claim_type ENUM('replace','send_company') NOT NULL,
    problem_description TEXT NOT NULL,
    claim_date DATE NOT NULL,
    status ENUM('pending','processing','approved','rejected','completed') DEFAULT 'pending',
    resolution TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warranty_id) REFERENCES warranties(id),
    FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- รูปภาพเคลมสินค้า
CREATE TABLE IF NOT EXISTS claim_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- คำสั่งซื้อ
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_id INT,
    order_number VARCHAR(50) UNIQUE,
    total_amount DECIMAL(10,2) DEFAULT 0,
    delivery_address TEXT,
    delivery_lat DECIMAL(10,8),
    delivery_lng DECIMAL(11,8),
    old_battery_image VARCHAR(500),
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES member_vehicles(id)
) ENGINE=InnoDB;

-- รายการสินค้าในคำสั่งซื้อ
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- บัญชีรายรับ-รายจ่าย
CREATE TABLE IF NOT EXISTS accounting (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('income','expense') NOT NULL,
    category VARCHAR(200) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description TEXT,
    reference_id INT,
    reference_type VARCHAR(50),
    transaction_date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ใบเสร็จ
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) UNIQUE,
    order_id INT,
    customer_id INT,
    total_amount DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','transfer','credit_card') DEFAULT 'cash',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- การแจ้งเตือน
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    title VARCHAR(300) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('low_stock','warranty_expire','new_order','claim','general') DEFAULT 'general',
    is_read TINYINT(1) DEFAULT 0,
    link VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =============================
-- ข้อมูลเริ่มต้น
-- =============================

-- Admin user (password: admin123)
INSERT INTO users (username, password, fullname, phone, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ผู้ดูแลระบบ', '0812345678', 'admin');

-- ยี่ห้อรถ
INSERT INTO car_brands (name, type) VALUES
('Toyota', 'car'),
('Honda', 'car'),
('Nissan', 'car'),
('Mitsubishi', 'car'),
('Mazda', 'car'),
('Suzuki', 'both'),
('Isuzu', 'car'),
('Ford', 'car'),
('Chevrolet', 'car'),
('BMW', 'car'),
('Mercedes-Benz', 'car'),
('Hyundai', 'car'),
('Kia', 'car'),
('MG', 'car'),
('Subaru', 'car'),
('Volvo', 'car'),
('Lexus', 'car'),
('Audi', 'car'),
('Volkswagen', 'car'),
('Honda (มอเตอร์ไซค์)', 'motorcycle'),
('Yamaha', 'motorcycle'),
('Kawasaki', 'motorcycle'),
('Suzuki (มอเตอร์ไซค์)', 'motorcycle'),
('GPX', 'motorcycle'),
('Vespa', 'motorcycle'),
('Ducati', 'motorcycle'),
('Harley-Davidson', 'motorcycle'),
('Royal Enfield', 'motorcycle'),
('CFMOTO', 'motorcycle'),
('Benelli', 'motorcycle');

-- หมวดหมู่สินค้า
INSERT INTO categories (name, description) VALUES
('แบตเตอรี่รถยนต์', 'แบตเตอรี่สำหรับรถยนต์ทุกประเภท'),
('แบตเตอรี่มอเตอร์ไซค์', 'แบตเตอรี่สำหรับรถจักรยานยนต์'),
('แบตเตอรี่รถบรรทุก', 'แบตเตอรี่สำหรับรถบรรทุกและรถขนาดใหญ่'),
('อุปกรณ์เสริม', 'อุปกรณ์เสริมสำหรับแบตเตอรี่');

-- ยี่ห้อแบตเตอรี่
INSERT INTO battery_brands (name) VALUES
('3K'),
('FB (Furukawa)'),
('GS'),
('Panasonic'),
('Bosch'),
('Amaron'),
('Atlas'),
('Yuasa'),
('DIN'),
('Varta');

-- ตัวอย่างสินค้า
INSERT INTO products (category_id, battery_brand_id, name, sku, description, price, cost_price, quantity, min_quantity, warranty_months, vehicle_type) VALUES
(1, 1, '3K แบตเตอรี่ NS60L', 'BAT-3K-NS60L', 'แบตเตอรี่ 3K ขนาด NS60L 45Ah สำหรับรถเก๋ง', 2200.00, 1600.00, 25, 5, 12, 'car'),
(1, 1, '3K แบตเตอรี่ NS60R', 'BAT-3K-NS60R', 'แบตเตอรี่ 3K ขนาด NS60R 45Ah สำหรับรถเก๋ง', 2200.00, 1600.00, 20, 5, 12, 'car'),
(1, 2, 'FB แบตเตอรี่ 46B24L', 'BAT-FB-46B24L', 'แบตเตอรี่ FB ขนาด 46B24L สำหรับรถ Honda, Toyota', 2800.00, 2100.00, 15, 5, 18, 'car'),
(1, 2, 'FB แบตเตอรี่ 55B24L', 'BAT-FB-55B24L', 'แบตเตอรี่ FB ขนาด 55B24L สำหรับรถเก๋ง', 3200.00, 2400.00, 18, 5, 18, 'car'),
(1, 3, 'GS แบตเตอรี่ MF 80D26L', 'BAT-GS-80D26L', 'แบตเตอรี่ GS Maintenance Free 80D26L', 3800.00, 2900.00, 10, 3, 24, 'car'),
(1, 4, 'Panasonic แบตเตอรี่ 55D23L', 'BAT-PAN-55D23L', 'แบตเตอรี่ Panasonic Caos 55D23L', 4200.00, 3200.00, 8, 3, 24, 'car'),
(1, 5, 'Bosch แบตเตอรี่ DIN55', 'BAT-BOSCH-DIN55', 'แบตเตอรี่ Bosch DIN55 สำหรับรถยุโรป', 4500.00, 3500.00, 6, 3, 24, 'car'),
(2, 8, 'Yuasa แบตเตอรี่ YTX5L-BS', 'BAT-YUA-YTX5L', 'แบตเตอรี่ Yuasa สำหรับมอเตอร์ไซค์ 110-125cc', 650.00, 450.00, 30, 10, 6, 'motorcycle'),
(2, 8, 'Yuasa แบตเตอรี่ YTX7A-BS', 'BAT-YUA-YTX7A', 'แบตเตอรี่ Yuasa สำหรับมอเตอร์ไซค์ 150cc+', 850.00, 600.00, 25, 10, 6, 'motorcycle'),
(2, 3, 'GS แบตเตอรี่ GTZ5S', 'BAT-GS-GTZ5S', 'แบตเตอรี่ GS MF สำหรับมอเตอร์ไซค์', 550.00, 380.00, 35, 10, 6, 'motorcycle');

-- ความเข้ากันได้ตัวอย่าง
INSERT INTO product_compatibility (product_id, car_brand_id, car_model) VALUES
(1, 1, 'Vios'),
(1, 1, 'Yaris'),
(1, 2, 'City'),
(1, 2, 'Jazz'),
(2, 1, 'Hilux Vigo'),
(2, 4, 'Triton'),
(3, 2, 'Civic'),
(3, 2, 'Accord'),
(3, 1, 'Camry'),
(4, 1, 'Fortuner'),
(4, 1, 'Innova'),
(5, 1, 'Fortuner'),
(5, 7, 'D-Max'),
(6, 2, 'CR-V'),
(6, 4, 'Pajero Sport'),
(7, 10, '3 Series'),
(7, 11, 'C-Class'),
(8, 20, 'Wave'),
(8, 20, 'Click'),
(8, 21, 'Mio'),
(9, 20, 'PCX'),
(9, 20, 'Forza'),
(10, 20, 'Wave 110i'),
(10, 21, 'Fino');

-- สินค้าแนะนำ
INSERT INTO recommended_products (product_id, car_brand_id, car_model, priority) VALUES
(1, 1, 'Vios', 1),
(1, 2, 'City', 1),
(3, 2, 'Civic', 1),
(4, 1, 'Fortuner', 1),
(5, 7, 'D-Max', 1),
(8, 20, 'Click', 1),
(9, 20, 'PCX', 1);

