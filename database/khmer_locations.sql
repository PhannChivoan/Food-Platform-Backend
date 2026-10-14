-- =============================================================
-- Khmer Locations Re-seed
-- Converts all geometry/address seed data from US Faker values
-- to real Khmer (Phnom Penh, Cambodia) addresses and coordinates.
-- Tables: customer_addresses, branches, rider_profiles
-- Geometry is written with SRID 0 to match the existing dump.
-- =============================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────
-- BRANCHES -> Phnom Penh
-- ─────────────────────────────────────────────

UPDATE `branches` SET
  `name` = 'Boeung Keng Kang Branch',
  `address` = 'Avenue Keo Chouka 42, បួងកេងកាង (Boeung Keng Kang), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12001',
  `location` = ST_GeomFromText('POINT(104.790000 11.525000)', 0),
  `updated_at` = NOW()
WHERE `id` = 1;

UPDATE `branches` SET
  `name` = 'Talyab Branch',
  `address` = 'St. 88, តាល្យាប (Talyab), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12002',
  `location` = ST_GeomFromText('POINT(104.735000 11.412000)', 0),
  `updated_at` = NOW()
WHERE `id` = 2;

UPDATE `branches` SET
  `name` = 'Wat Phnom Branch',
  `address` = 'Hostel Street 231, វត្តព្នូម (Wat Phnom), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12003',
  `location` = ST_GeomFromText('POINT(104.886200 11.598500)', 0),
  `updated_at` = NOW()
WHERE `id` = 3;

-- ─────────────────────────────────────────────
-- CUSTOMER ADDRESSES -> Phnom Penh
-- ─────────────────────────────────────────────

UPDATE `customer_addresses` SET
  `address` = 'Boulevard Sisowath 45, បួងកេងកាង (Boeung Keng Kang), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12004',
  `location` = ST_GeomFromText('POINT(104.790500 11.524800)', 0),
  `updated_at` = NOW()
WHERE `id` = 1;

UPDATE `customer_addresses` SET
  `address` = 'St. 12, វត្តព្នូម (Wat Phnom), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12005',
  `location` = ST_GeomFromText('POINT(104.886200 11.598500)', 0),
  `updated_at` = NOW()
WHERE `id` = 2;

UPDATE `customer_addresses` SET
  `address` = 'Avenue Keo Chouka 7, ទួលទាំងបួង (Tuol Tiang Boeung), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12006',
  `location` = ST_GeomFromText('POINT(104.901000 11.590000)', 0),
  `updated_at` = NOW()
WHERE `id` = 3;

UPDATE `customer_addresses` SET
  `address` = 'Chak Bouer 15, ដៅនពេន (Daun Penh), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12007',
  `location` = ST_GeomFromText('POINT(104.905000 11.588500)', 0),
  `updated_at` = NOW()
WHERE `id` = 4;

UPDATE `customer_addresses` SET
  `address` = 'St. 321, បួងក្រាំងត្រែម (Boeung Krong Trem), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12008',
  `location` = ST_GeomFromText('POINT(104.796000 11.478000)', 0),
  `updated_at` = NOW()
WHERE `id` = 5;

UPDATE `customer_addresses` SET
  `address` = 'Talyab Road 9, តាល្យាប (Talyab), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12009',
  `location` = ST_GeomFromText('POINT(104.735000 11.412000)', 0),
  `updated_at` = NOW()
WHERE `id` = 6;

UPDATE `customer_addresses` SET
  `address` = 'Royal Plaza Avenue 2, ស្រាច្យ (Sracy), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12010',
  `location` = ST_GeomFromText('POINT(104.928200 11.556400)', 0),
  `updated_at` = NOW()
WHERE `id` = 7;

UPDATE `customer_addresses` SET
  `address` = 'St. 77, ទួលទាំងបួង (Tuol Tiang Boeung), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12011',
  `location` = ST_GeomFromText('POINT(104.900000 11.590500)', 0),
  `updated_at` = NOW()
WHERE `id` = 8;

UPDATE `customer_addresses` SET
  `address` = 'Boulevard Svay Srun 3, បួងកេងកាង (Boeung Keng Kang), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12012',
  `location` = ST_GeomFromText('POINT(104.790000 11.525500)', 0),
  `updated_at` = NOW()
WHERE `id` = 9;

UPDATE `customer_addresses` SET
  `address` = 'Wat Phnom Street 5, វត្តព្នូម (Wat Phnom), ព្នូមពេន (Phnom Penh)',
  `city` = 'Phnom Penh',
  `postal_code` = '12013',
  `location` = ST_GeomFromText('POINT(104.886000 11.598800)', 0),
  `updated_at` = NOW()
WHERE `id` = 10;

-- ─────────────────────────────────────────────
-- RIDER PROFILES -> Phnom Penh (current_location)
-- ─────────────────────────────────────────────

UPDATE `rider_profiles` SET
  `current_location` = ST_GeomFromText('POINT(104.905000 11.588000)', 0),
  `updated_at` = NOW()
WHERE `id` = 1;

UPDATE `rider_profiles` SET
  `current_location` = ST_GeomFromText('POINT(104.792000 11.526000)', 0),
  `updated_at` = NOW()
WHERE `id` = 2;

UPDATE `rider_profiles` SET
  `current_location` = ST_GeomFromText('POINT(104.885000 11.598000)', 0),
  `updated_at` = NOW()
WHERE `id` = 3;

-- ─────────────────────────────────────────────
-- VERIFY
-- ─────────────────────────────────────────────

SELECT 'branches' AS `table`, `id`, ST_X(`location`) AS `lng`, ST_Y(`location`) AS `lat`, `city` FROM `branches`
UNION ALL
SELECT 'customer_addresses', `id`, ST_X(`location`), ST_Y(`location`), `city` FROM `customer_addresses`
UNION ALL
SELECT 'rider_profiles', `id`, ST_X(`current_location`), ST_Y(`current_location`), 'Phnom Penh' FROM `rider_profiles`;