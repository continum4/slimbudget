CREATE TABLE people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  spending_is_income TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'What this person spends also counts as income for the month',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','member') NOT NULL DEFAULT 'member',
  person_id INT UNSIGNED NULL,
  locale VARCHAR(10) NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (person_id) REFERENCES people(id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (username, ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE category_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  mode ENUM('fixed','auto','installments') NOT NULL DEFAULT 'auto' COMMENT 'Default estimate mode when a month is opened',
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE (group_id, name),
  FOREIGN KEY (group_id) REFERENCES category_groups(id)
) ENGINE=InnoDB;

CREATE TABLE cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  alias VARCHAR(40) NULL,
  category_id INT UNSIGNED NULL COMMENT 'Debt category where its installments are budgeted',
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE months (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ym CHAR(7) NOT NULL UNIQUE COMMENT 'YYYY-MM',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE incomes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  month_id INT UNSIGNED NOT NULL,
  kind ENUM('salary','other','savings') NOT NULL,
  description VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (month_id) REFERENCES months(id)
) ENGINE=InnoDB;

CREATE TABLE estimates (
  month_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  mode ENUM('fixed','auto','installments') NOT NULL DEFAULT 'fixed',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (month_id, category_id),
  FOREIGN KEY (month_id) REFERENCES months(id),
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE installment_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  concept VARCHAR(120) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  installments SMALLINT UNSIGNED NOT NULL,
  start_month CHAR(7) NOT NULL,
  end_month CHAR(7) NOT NULL,
  purchased_on DATE NULL,
  merchant VARCHAR(80) NULL,
  settled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Paid off early: leaves no balance',
  note VARCHAR(255) NULL,
  KEY (start_month, end_month),
  FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB;

CREATE TABLE bill_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  day TINYINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  category_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL COMMENT 'If set, progress is everything spent in that group',
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (group_id) REFERENCES category_groups(id)
) ENGINE=InnoDB;

CREATE TABLE bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  month_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  due_on DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  category_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  unlinked_payment DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Paid without a linked expense',
  UNIQUE (month_id, template_id),
  FOREIGN KEY (month_id) REFERENCES months(id),
  FOREIGN KEY (template_id) REFERENCES bill_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (group_id) REFERENCES category_groups(id)
) ENGINE=InnoDB;

CREATE TABLE expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  month_id INT UNSIGNED NOT NULL,
  spent_on DATE NOT NULL,
  description VARCHAR(150) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  card_id INT UNSIGNED NULL,
  merchant VARCHAR(80) NULL,
  bill_id INT UNSIGNED NULL,
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY (month_id, category_id),
  KEY (spent_on),
  FOREIGN KEY (month_id) REFERENCES months(id),
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (person_id) REFERENCES people(id),
  FOREIGN KEY (card_id) REFERENCES cards(id),
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE settings (
  name VARCHAR(40) PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
