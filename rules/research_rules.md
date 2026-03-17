# Research Database Rules

## Purpose
These rules govern the storage, access, and management of research data within the `research_db` MySQL database.

## Data Model
- **Table: research_articles**
  - `id` INT AUTO_INCREMENT PRIMARY KEY
  - `title` VARCHAR(255) NOT NULL
  - `authors` TEXT
  - `abstract` TEXT
  - `content` LONGTEXT
  - `published_date` DATE
  - `tags` VARCHAR(255)
  - `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  - `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

- **Table: research_sources**
  - `id` INT AUTO_INCREMENT PRIMARY KEY
  - `article_id` INT NOT NULL (FK to research_articles.id)
  - `source_type` ENUM('journal','conference','website','book')
  - `source_detail` TEXT
  - `accessed_at` DATETIME

## Access Control
- Only authorized users with the `research_admin` role may INSERT, UPDATE, or DELETE records.
- Users with the `research_viewer` role may SELECT data but cannot modify it.
- All access must be logged in an audit table (not shown) with user ID, timestamp, and query details.

## Data Integrity
- Enforce NOT NULL constraints on `title` and `published_date`.
- Use foreign key constraints between `research_sources.article_id` and `research_articles.id`.
- Implement appropriate indexes on `published_date`, `tags`, and `source_type` for performance.

## Security
- Store the database credentials securely; never hard‑code them in application code.
- Use TLS/SSL for any remote connections to the MySQL server.
- Regularly backup the database and retain backups for at least 30 days.

## Retention & Deletion
- Articles older than 10 years may be archived to a separate storage system.
- Deleting an article must cascade delete its associated sources.

## Compliance
- Ensure that any personal data stored complies with GDPR/CCPA regulations.
- Maintain a data processing agreement (DPA) for any third‑party data sources.

---
*These rules are intended as a guideline; adapt them to your specific organizational policies.*