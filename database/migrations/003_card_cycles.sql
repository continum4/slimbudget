CREATE TABLE card_cycles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  from_month CHAR(7) NOT NULL DEFAULT '0000-00' COMMENT 'First statement closing month it applies to; 0000-00 is the beginning',
  closing_day TINYINT UNSIGNED NULL COMMENT 'NULL means calendar month',
  due_day TINYINT UNSIGNED NULL,
  UNIQUE (card_id, from_month),
  FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO card_cycles (card_id, closing_day, due_day)
  SELECT id, closing_day, due_day FROM cards WHERE closing_day IS NOT NULL OR due_day IS NOT NULL;

ALTER TABLE cards DROP COLUMN closing_day, DROP COLUMN due_day;
