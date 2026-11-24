ALTER TABLE cards
  ADD closing_day TINYINT UNSIGNED NULL COMMENT 'Statement closing day; NULL means calendar month' AFTER category_id,
  ADD due_day TINYINT UNSIGNED NULL COMMENT 'Payment due day' AFTER closing_day;
