SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================= users =================
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `phone` VARCHAR(255) NULL,
  `avatar` VARCHAR(255) NULL,
  `role` ENUM('admin','penerima','donatur') NOT NULL DEFAULT 'donatur',
  `status_verifikasi` ENUM('pending','approved','rejected') NULL,
  `email_verified_at` TIMESTAMP NULL,
  `password` VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= sessions & password reset =================
CREATE TABLE `password_reset_tokens` (
  `email` VARCHAR(255) PRIMARY KEY,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sessions` (
  `id` VARCHAR(255) PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `payload` LONGTEXT NOT NULL,
  `last_activity` INT NOT NULL,
  INDEX (`user_id`),
  INDEX (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= cache & queue =================
CREATE TABLE `cache` (
  `key` VARCHAR(255) PRIMARY KEY,
  `value` MEDIUMTEXT NOT NULL,
  `expiration` BIGINT NOT NULL,
  INDEX (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cache_locks` (
  `key` VARCHAR(255) PRIMARY KEY,
  `owner` VARCHAR(255) NOT NULL,
  `expiration` BIGINT NOT NULL,
  INDEX (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  INDEX (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `job_batches` (
  `id` VARCHAR(255) PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `total_jobs` INT NOT NULL,
  `pending_jobs` INT NOT NULL,
  `failed_jobs` INT NOT NULL,
  `failed_job_ids` LONGTEXT NOT NULL,
  `options` MEDIUMTEXT NULL,
  `cancelled_at` INT NULL,
  `created_at` INT NOT NULL,
  `finished_at` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `failed_jobs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(255) NOT NULL UNIQUE,
  `connection` VARCHAR(255) NOT NULL,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`connection`, `queue`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= penerima_profiles =================
CREATE TABLE `penerima_profiles` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `nama_organisasi` VARCHAR(255) NOT NULL,
  `jenis_organisasi` ENUM('yayasan','individu','komunitas') NOT NULL,
  `alamat` TEXT NOT NULL,
  `nomor_rekening` VARCHAR(255) NULL,
  `dokumen_ktp` VARCHAR(255) NULL,
  `dokumen_legalitas` VARCHAR(255) NULL,
  `alasan_ditolak` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `fk_penerima_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= categories =================
CREATE TABLE `categories` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nama` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `icon` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= item_categories =================
CREATE TABLE `item_categories` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nama` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= campaigns =================
CREATE TABLE `campaigns` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NULL,
  `kategori_lainnya` VARCHAR(255) NULL,
  `judul` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `deskripsi` TEXT NOT NULL,
  `tipe_bantuan` ENUM('uang','barang','keduanya') NOT NULL,
  `target_nominal` DECIMAL(15,2) NULL,
  `terkumpul_nominal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `target_barang` VARCHAR(255) NULL,
  `satuan_barang` VARCHAR(255) NULL,
  `terkumpul_barang` INT UNSIGNED NOT NULL DEFAULT 0,
  `urgensi` ENUM('mendesak','biasa','terjadwal') NOT NULL DEFAULT 'biasa',
  `tanggal_mulai` DATE NOT NULL,
  `tanggal_selesai` DATE NOT NULL,
  `foto_sampul` VARCHAR(255) NULL,
  `qr_code_path` VARCHAR(255) NULL,
  `status` ENUM('draft','pending_approval','aktif','selesai','ditolak') NOT NULL DEFAULT 'draft',
  `alasan_ditolak` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  CONSTRAINT `fk_campaigns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaigns_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= donations =================
CREATE TABLE `donations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `jenis` ENUM('uang','barang') NOT NULL,
  `is_anonim` TINYINT(1) NOT NULL DEFAULT 0,
  `nominal` DECIMAL(15,2) NULL,
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `frequency` ENUM('mingguan','bulanan') NULL,
  `order_id` VARCHAR(255) NULL,
  `item_category_id` BIGINT UNSIGNED NULL,
  `nama_barang` VARCHAR(255) NULL,
  `jumlah_barang` INT UNSIGNED NULL,
  `satuan_barang` VARCHAR(255) NULL,
  `foto_barang` VARCHAR(255) NULL,
  `catatan` TEXT NULL,
  `metode_pengiriman` ENUM('dikirim_sendiri','diantar_langsung') NULL,
  `status` ENUM('menunggu_pembayaran','terverifikasi','diterima','gagal') NOT NULL DEFAULT 'menunggu_pembayaran',
  `tanggal_diterima` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  CONSTRAINT `fk_donations_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_donations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_donations_item_category` FOREIGN KEY (`item_category_id`) REFERENCES `item_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= shipments =================
CREATE TABLE `shipments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `donation_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `ekspedisi` VARCHAR(255) NULL,
  `no_resi` VARCHAR(255) NULL,
  `estimasi_ongkir` DECIMAL(12,2) NULL,
  `status` ENUM('dikirim','dalam_perjalanan','diterima') NOT NULL DEFAULT 'dikirim',
  `last_tracked_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `fk_shipments_donation` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= conversations =================
CREATE TABLE `conversations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `donatur_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`campaign_id`, `donatur_id`),
  CONSTRAINT `fk_conversations_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conversations_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= messages =================
CREATE TABLE `messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `isi` TEXT NOT NULL,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `fk_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= campaign_reports =================
CREATE TABLE `campaign_reports` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `judul` VARCHAR(255) NOT NULL,
  `isi` TEXT NOT NULL,
  `foto_bukti` VARCHAR(255) NULL,
  `nominal_terpakai` DECIMAL(15,2) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `fk_campaign_reports_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================= broadcast_notifications =================
CREATE TABLE `broadcast_notifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `judul` VARCHAR(255) NOT NULL,
  `isi` TEXT NOT NULL,
  `target_role` ENUM('semua','donatur','penerima') NOT NULL DEFAULT 'semua',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  CONSTRAINT `fk_broadcast_notifications_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
