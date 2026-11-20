-- Kisenyi Central Boda-Boda Stage Association MIS
-- MySQL 8+ / MariaDB 10.5+. Import this file into phpMyAdmin.
CREATE DATABASE IF NOT EXISTS kcbsa_mis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kcbsa_mis;
SET NAMES utf8mb4;
CREATE TABLE RIDER (
  rider_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  national_id VARCHAR(32) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  address VARCHAR(180) NOT NULL,
  date_joined DATE NOT NULL,
  status ENUM('Active','Suspended','Inactive') NOT NULL DEFAULT 'Active',
  next_of_kin_name VARCHAR(120) NULL,
  next_of_kin_phone VARCHAR(32) NULL,
  PRIMARY KEY (rider_id),
  UNIQUE KEY uq_rider_national_id (national_id),
  KEY idx_rider_name (full_name),
  KEY idx_rider_status (status),
  KEY idx_rider_joined (date_joined)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE MOTORCYCLE (
  motorcycle_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NOT NULL,
  plate_number VARCHAR(20) NOT NULL,
  make_model VARCHAR(80) NOT NULL,
  colour VARCHAR(40) NULL,
  PRIMARY KEY (motorcycle_id),
  UNIQUE KEY uq_motorcycle_plate (plate_number),
  KEY idx_motorcycle_rider (rider_id),
  CONSTRAINT fk_motorcycle_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE USER_ACCOUNT (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NULL,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Secretary','Treasurer','Welfare Officer','Safety Officer','Chairperson','Rider') NOT NULL,
  status ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_user_username (username),
  UNIQUE KEY uq_user_rider (rider_id),
  KEY idx_user_role_status (role,status),
  CONSTRAINT fk_user_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE PERMIT_INSURANCE (
  doc_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  motorcycle_id INT UNSIGNED NOT NULL,
  doc_type ENUM('Permit','Insurance') NOT NULL,
  doc_number VARCHAR(80) NOT NULL,
  issue_date DATE NOT NULL,
  expiry_date DATE NOT NULL,
  PRIMARY KEY (doc_id),
  KEY idx_doc_expiry_type (expiry_date,doc_type),
  KEY idx_doc_motorcycle (motorcycle_id),
  CONSTRAINT fk_doc_motorcycle FOREIGN KEY (motorcycle_id) REFERENCES MOTORCYCLE(motorcycle_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE DUES_PAYMENT (
  payment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  period_covered VARCHAR(80) NOT NULL,
  method ENUM('Cash','Mobile Money') NOT NULL,
  PRIMARY KEY (payment_id),
  KEY idx_dues_rider_date (rider_id,payment_date),
  KEY idx_dues_date (payment_date),
  KEY idx_dues_recorder (recorded_by),
  CONSTRAINT fk_dues_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT,
  CONSTRAINT fk_dues_recorder FOREIGN KEY (recorded_by) REFERENCES USER_ACCOUNT(user_id) ON DELETE RESTRICT,
  CONSTRAINT chk_dues_nonzero CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE SAVINGS_TRANSACTION (
  savings_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  trans_type ENUM('Deposit','Withdrawal') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  trans_date DATE NOT NULL,
  PRIMARY KEY (savings_id),
  KEY idx_savings_rider_date (rider_id,trans_date),
  KEY idx_savings_recorder (recorded_by),
  CONSTRAINT fk_savings_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT,
  CONSTRAINT fk_savings_recorder FOREIGN KEY (recorded_by) REFERENCES USER_ACCOUNT(user_id) ON DELETE RESTRICT,
  CONSTRAINT chk_savings_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE WELFARE_CLAIM (
  claim_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NOT NULL,
  decided_by INT UNSIGNED NULL,
  claim_type ENUM('Accident','Sickness','Funeral','Emergency') NOT NULL,
  amount_requested DECIMAL(12,2) NOT NULL,
  amount_approved DECIMAL(12,2) NULL,
  status ENUM('Submitted','Approved','Rejected','Pending','Paid') NOT NULL DEFAULT 'Submitted',
  date_submitted DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decision_reason VARCHAR(500) NULL,
  PRIMARY KEY (claim_id),
  KEY idx_claim_status_date (status,date_submitted),
  KEY idx_claim_rider_date (rider_id,date_submitted),
  KEY idx_claim_decider (decided_by),
  CONSTRAINT fk_claim_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT,
  CONSTRAINT fk_claim_decider FOREIGN KEY (decided_by) REFERENCES USER_ACCOUNT(user_id) ON DELETE RESTRICT,
  CONSTRAINT chk_claim_requested CHECK (amount_requested > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE INCIDENT (
  incident_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rider_id INT UNSIGNED NOT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  incident_date DATE NOT NULL,
  incident_type ENUM('Accident','Discipline','Other') NOT NULL,
  description VARCHAR(1000) NOT NULL,
  action_taken VARCHAR(1000) NULL,
  PRIMARY KEY (incident_id),
  KEY idx_incident_rider_date (rider_id,incident_date),
  KEY idx_incident_type_date (incident_type,incident_date),
  CONSTRAINT fk_incident_rider FOREIGN KEY (rider_id) REFERENCES RIDER(rider_id) ON DELETE RESTRICT,
  CONSTRAINT fk_incident_recorder FOREIGN KEY (recorded_by) REFERENCES USER_ACCOUNT(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE SETTINGS (
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE AUDIT_LOG (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL,
  table_name VARCHAR(80) NOT NULL,
  record_id BIGINT UNSIGNED NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_actor_created (user_id,created_at),
  KEY idx_audit_table_record (table_name,record_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES USER_ACCOUNT(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO SETTINGS (setting_key,setting_value) VALUES
  ('weekly_dues','5000'),('arrears_limit','20000'),('alert_days','30'),('welfare_share_percent','30');

-- Demonstration membership spanning the last 12 months.
INSERT INTO RIDER (rider_id,national_id,full_name,phone,address,date_joined,status,next_of_kin_name,next_of_kin_phone) VALUES
(1,'CM900101001','Amina Nansubuga','0701001001','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 364 DAY),'Active','Sarah Nansubuga','0702001001'),
(2,'CM900202002','Musa Ssemanda','0701001002','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 349 DAY),'Active','Hassan Ssemanda','0702001002'),
(3,'CM900303003','Peter Kato','0701001003','Mengo, Kampala',DATE_SUB(CURDATE(),INTERVAL 334 DAY),'Active','Grace Kato','0702001003'),
(4,'CM900404004','Josephine Nakato','0701001004','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 319 DAY),'Active','Paul Nakato','0702001004'),
(5,'CM900505005','Brian Mugisha','0701001005','Katwe, Kampala',DATE_SUB(CURDATE(),INTERVAL 304 DAY),'Active','Diana Mugisha','0702001005'),
(6,'CM900606006','Sarah Namusoke','0701001006','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 289 DAY),'Active','David Namusoke','0702001006'),
(7,'CM900707007','Ivan Kiggundu','0701001007','Nateete, Kampala',DATE_SUB(CURDATE(),INTERVAL 274 DAY),'Active','Mary Kiggundu','0702001007'),
(8,'CM900808008','Mariam Nabbosa','0701001008','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 259 DAY),'Active','John Nabbosa','0702001008'),
(9,'CM900909009','David Ssekandi','0701001009','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 244 DAY),'Active','Ruth Ssekandi','0702001009'),
(10,'CM901010010','Esther Atim','0701001010','Old Kampala',DATE_SUB(CURDATE(),INTERVAL 229 DAY),'Active','Peter Atim','0702001010'),
(11,'CM901111011','Ronald Bukenya','0701001011','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 214 DAY),'Active','Joy Bukenya','0702001011'),
(12,'CM901212012','Hawa Nakitende','0701001012','Mengo, Kampala',DATE_SUB(CURDATE(),INTERVAL 199 DAY),'Active','Ali Nakitende','0702001012'),
(13,'CM901313013','Samuel Okello','0701001013','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 184 DAY),'Active','Lydia Okello','0702001013'),
(14,'CM901414014','Patrick Lwanga','0701001014','Katwe, Kampala',DATE_SUB(CURDATE(),INTERVAL 169 DAY),'Active','Jane Lwanga','0702001014'),
(15,'CM901515015','Rebecca Akello','0701001015','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 154 DAY),'Active','Michael Akello','0702001015'),
(16,'CM901616016','Emmanuel Ssentongo','0701001016','Mengo, Kampala',DATE_SUB(CURDATE(),INTERVAL 139 DAY),'Active','Rose Ssentongo','0702001016'),
(17,'CM901717017','Rita Namaganda','0701001017','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 124 DAY),'Active','Alex Namaganda','0702001017'),
(18,'CM901818018','Denis Waiswa','0701001018','Nateete, Kampala',DATE_SUB(CURDATE(),INTERVAL 109 DAY),'Active','Martha Waiswa','0702001018'),
(19,'CM901919019','Florence Nambasa','0701001019','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 94 DAY),'Active','James Nambasa','0702001019'),
(20,'CM902020020','Andrew Ssebugwawo','0701001020','Old Kampala',DATE_SUB(CURDATE(),INTERVAL 79 DAY),'Active','Catherine Ssebugwawo','0702001020'),
(21,'CM902121021','Noah Kizito','0701001021','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 64 DAY),'Active','Alice Kizito','0702001021'),
(22,'CM902222022','Joyce Mirembe','0701001022','Katwe, Kampala',DATE_SUB(CURDATE(),INTERVAL 49 DAY),'Active','Stephen Mirembe','0702001022'),
(23,'CM902323023','Robert Ssewankambo','0701001023','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 34 DAY),'Active','Eunice Ssewankambo','0702001023'),
(24,'CM902424024','Agnes Nalubega','0701001024','Mengo, Kampala',DATE_SUB(CURDATE(),INTERVAL 19 DAY),'Active','John Nalubega','0702001024'),
(25,'CM902525025','George Kayiwa','0701001025','Kisenyi, Kampala',DATE_SUB(CURDATE(),INTERVAL 7 DAY),'Suspended','Moses Kayiwa','0702001025');

-- The same demo password hash was created with PHP password_hash() for the README accounts.
INSERT INTO USER_ACCOUNT (user_id,rider_id,username,password_hash,role,status) VALUES
(1,NULL,'secretary','$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Secretary','Active'),
(2,NULL,'treasurer','$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Treasurer','Active'),
(3,NULL,'welfare','$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Welfare Officer','Active'),
(4,NULL,'safety','$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Safety Officer','Active'),
(5,NULL,'chairperson','$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Chairperson','Active');
INSERT INTO USER_ACCOUNT (rider_id,username,password_hash,role,status)
SELECT rider_id,CONCAT('rider',LPAD(rider_id,3,'0')),
       '$2y$12$h6DO3ZH5Xa.SsE280w.U..k0sxV8vk/C4hgmB.5pllpUhIzDB.yuy','Rider','Active' FROM RIDER;

INSERT INTO MOTORCYCLE (motorcycle_id,rider_id,plate_number,make_model,colour) VALUES
(1,1,'UFG 101A','Bajaj Boxer','Black'),(2,2,'UFG 102A','TVS HLX','Blue'),
(3,3,'UFG 103A','Bajaj Boxer','Red'),(4,4,'UFG 104A','TVS HLX','Black'),
(5,5,'UFG 105A','Bajaj Boxer','Blue'),(6,6,'UFG 106A','Honda Ace','Red'),
(7,7,'UFG 107A','TVS HLX','Black'),(8,8,'UFG 108A','Bajaj Boxer','White'),
(9,9,'UFG 109A','TVS HLX','Blue'),(10,10,'UFG 110A','Bajaj Boxer','Black'),
(11,11,'UFG 111A','Honda Ace','White'),(12,12,'UFG 112A','TVS HLX','Red'),
(13,13,'UFG 113A','Bajaj Boxer','Black'),(14,14,'UFG 114A','TVS HLX','Blue'),
(15,15,'UFG 115A','Bajaj Boxer','Red'),(16,16,'UFG 116A','Honda Ace','Black'),
(17,17,'UFG 117A','TVS HLX','White'),(18,18,'UFG 118A','Bajaj Boxer','Blue'),
(19,19,'UFG 119A','TVS HLX','Black'),(20,20,'UFG 120A','Bajaj Boxer','Red'),
(21,21,'UFG 121A','Honda Ace','Blue'),(22,22,'UFG 122A','TVS HLX','Black'),
(23,23,'UFG 123A','Bajaj Boxer','White'),(24,24,'UFG 124A','TVS HLX','Red'),
(25,25,'UFG 125A','Bajaj Boxer','Black'),(26,1,'UFG 126A','TVS HLX','Blue'),
(27,5,'UFG 127A','Honda Ace','White'),(28,9,'UFG 128A','Bajaj Boxer','Red');

INSERT INTO PERMIT_INSURANCE (motorcycle_id,doc_type,doc_number,issue_date,expiry_date)
SELECT m.motorcycle_id, types.doc_type,
       CONCAT(IF(types.doc_type='Permit','PRM','INS'),'-KCBSA-',LPAD(m.motorcycle_id,4,'0')),
       DATE_SUB(CURDATE(),INTERVAL 11 MONTH),
       CASE
         WHEN m.motorcycle_id IN (2,9,15,22) AND types.doc_type='Permit' THEN DATE_SUB(CURDATE(),INTERVAL 12 DAY)
         WHEN m.motorcycle_id IN (4,11,18,27) AND types.doc_type='Insurance' THEN DATE_ADD(CURDATE(),INTERVAL 18 DAY)
         WHEN m.motorcycle_id IN (5,16,24) THEN DATE_ADD(CURDATE(),INTERVAL 8 DAY)
         ELSE DATE_ADD(CURDATE(),INTERVAL 200 DAY)
       END
FROM MOTORCYCLE m
CROSS JOIN (SELECT 'Permit' AS doc_type UNION ALL SELECT 'Insurance') types;

-- 52 weekly records over 12 months; five riders have missed recent weeks.
INSERT INTO DUES_PAYMENT (rider_id,recorded_by,amount,payment_date,period_covered,method)
SELECT r.rider_id,2,5000,DATE_SUB(CURDATE(),INTERVAL (weeks.week_no*7) DAY),
       CONCAT(YEARWEEK(DATE_SUB(CURDATE(),INTERVAL (weeks.week_no*7) DAY),3) DIV 100,'-W',
              LPAD(MOD(YEARWEEK(DATE_SUB(CURDATE(),INTERVAL (weeks.week_no*7) DAY),3),100),2,'0')),
       IF(MOD(r.rider_id+weeks.week_no,3)=0,'Mobile Money','Cash')
FROM RIDER r
CROSS JOIN (
  SELECT 0 week_no UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
  UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
  UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17
  UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
  UNION ALL SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
  UNION ALL SELECT 30 UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL SELECT 35
  UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40 UNION ALL SELECT 41
  UNION ALL SELECT 42 UNION ALL SELECT 43 UNION ALL SELECT 44 UNION ALL SELECT 45 UNION ALL SELECT 46 UNION ALL SELECT 47
  UNION ALL SELECT 48 UNION ALL SELECT 49 UNION ALL SELECT 50 UNION ALL SELECT 51
) weeks
WHERE DATE_SUB(CURDATE(),INTERVAL (weeks.week_no*7) DAY) >= r.date_joined
  AND NOT (r.rider_id IN (4,9,15,20,21) AND weeks.week_no BETWEEN 0 AND 6);

INSERT INTO SAVINGS_TRANSACTION (rider_id,recorded_by,trans_type,amount,trans_date) VALUES
(1,2,'Deposit',20000,DATE_SUB(CURDATE(),INTERVAL 20 DAY)),
(1,2,'Deposit',10000,DATE_SUB(CURDATE(),INTERVAL 10 DAY)),
(2,2,'Deposit',15000,DATE_SUB(CURDATE(),INTERVAL 18 DAY)),
(2,2,'Withdrawal',5000,DATE_SUB(CURDATE(),INTERVAL 8 DAY)),
(3,2,'Deposit',25000,DATE_SUB(CURDATE(),INTERVAL 12 DAY)),
(6,2,'Deposit',10000,DATE_SUB(CURDATE(),INTERVAL 15 DAY)),
(8,2,'Deposit',30000,DATE_SUB(CURDATE(),INTERVAL 30 DAY)),
(8,2,'Withdrawal',10000,DATE_SUB(CURDATE(),INTERVAL 5 DAY)),
(12,2,'Deposit',12000,DATE_SUB(CURDATE(),INTERVAL 7 DAY)),
(16,2,'Deposit',18000,DATE_SUB(CURDATE(),INTERVAL 22 DAY));

-- Eight example claims in each workflow status.
INSERT INTO WELFARE_CLAIM (rider_id,decided_by,claim_type,amount_requested,amount_approved,status,date_submitted,decision_reason)
SELECT MOD(n.n+s.status_no,25)+1,
       IF(s.status='Submitted',NULL,3),
       ELT(MOD(n.n,4)+1,'Accident','Sickness','Funeral','Emergency'),
       50000+(n.n*10000),
       CASE WHEN s.status IN ('Approved','Pending','Paid') THEN 40000+(n.n*5000) ELSE NULL END,
       s.status,
       DATE_SUB(NOW(),INTERVAL (n.n+s.status_no+1) DAY),
       CASE WHEN s.status='Rejected' THEN 'Reviewed by Welfare Officer'
            WHEN s.status='Pending' THEN 'Approved; awaiting available welfare fund'
            WHEN s.status='Paid' THEN 'Approved and paid'
            ELSE NULL END
FROM
  (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) n
CROSS JOIN
  (SELECT 0 status_no,'Submitted' status UNION ALL SELECT 1,'Approved' UNION ALL SELECT 2,'Rejected' UNION ALL SELECT 3,'Pending' UNION ALL SELECT 4,'Paid') s;

-- Seed payment audit entries so the historical payout trend has dates.
INSERT INTO AUDIT_LOG (user_id,action,table_name,record_id,details,created_at)
SELECT 2,'PAY','WELFARE_CLAIM',claim_id,CONCAT('{"amount":',amount_approved,'}'),date_submitted
FROM WELFARE_CLAIM WHERE status='Paid';

INSERT INTO INCIDENT (rider_id,recorded_by,incident_date,incident_type,description,action_taken) VALUES
(3,4,DATE_SUB(CURDATE(),INTERVAL 4 DAY),'Accident','Minor collision at Kisenyi junction; no serious injuries.','Safety briefing and incident follow-up'),
(6,4,DATE_SUB(CURDATE(),INTERVAL 18 DAY),'Discipline','Reported parking outside the marked stage area.','Verbal warning recorded'),
(9,4,DATE_SUB(CURDATE(),INTERVAL 31 DAY),'Accident','Motorcycle slipped during wet weather.','Rider referred for insurance follow-up'),
(12,4,DATE_SUB(CURDATE(),INTERVAL 42 DAY),'Other','Passenger reported a lost item after a trip.','Report documented; no further action'),
(15,4,DATE_SUB(CURDATE(),INTERVAL 57 DAY),'Discipline','Repeated queue-jumping complaint.','Written warning and stage meeting'),
(18,4,DATE_SUB(CURDATE(),INTERVAL 68 DAY),'Accident','Low-speed fall near the taxi park.','Welfare claim information shared'),
(20,4,DATE_SUB(CURDATE(),INTERVAL 83 DAY),'Other','Motorcycle temporarily unavailable for repairs.','Record updated'),
(22,4,DATE_SUB(CURDATE(),INTERVAL 101 DAY),'Discipline','Helmet compliance reminder issued.','Safety check completed'),
(24,4,DATE_SUB(CURDATE(),INTERVAL 126 DAY),'Accident','Minor property damage reported by rider.','Incident statement recorded'),
(25,4,DATE_SUB(CURDATE(),INTERVAL 142 DAY),'Other','Membership status review opened.','Referred to Chairperson');

ALTER TABLE USER_ACCOUNT AUTO_INCREMENT=100;
