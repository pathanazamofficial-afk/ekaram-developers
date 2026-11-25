CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  location VARCHAR(255) NOT NULL,
  total_land_area DECIMAL(10,2) NOT NULL DEFAULT 0,
  plot_count INT UNSIGNED NOT NULL DEFAULT 0,
  price_per_sqyd DECIMAL(12,2) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  description TEXT NULL,
  brochure_url VARCHAR(500) NULL,
  map_embed_url VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_projects_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS amenities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  display_order INT UNSIGNED NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_amenities_project_published (project_id, published, display_order),
  CONSTRAINT fk_amenities_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  plot_number VARCHAR(50) NOT NULL,
  dimensions VARCHAR(100) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'available',
  total_price DECIMAL(14,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plots_project_number (project_id, plot_number),
  KEY idx_plots_project_status (project_id, status),
  CONSTRAINT fk_plots_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(180) NULL,
  interest VARCHAR(40) NOT NULL DEFAULT 'general',
  message VARCHAR(2000) NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'public_website',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_customer_leads_phone_created (phone, created_at),
  KEY idx_customer_leads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
