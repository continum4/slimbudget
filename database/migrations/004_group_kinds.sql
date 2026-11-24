ALTER TABLE category_groups
  ADD kind ENUM('variable','fixed','savings') NOT NULL DEFAULT 'variable' COMMENT 'Trends leave savings out and can hide fixed groups' AFTER name;
