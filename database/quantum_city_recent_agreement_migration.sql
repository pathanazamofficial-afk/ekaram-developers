ALTER TABLE plots
  MODIFY status ENUM('available','reserved','booked','recent_agreement','sold','blocked') NOT NULL DEFAULT 'available';
