-- Demo location history for JARVIS demo users
-- This file contains sample location data for testing and demonstration purposes
-- Import into your database with: mysql < demo_location_history.sql
-- Or use the PHP import script: php scripts/import_demo_locations.php

-- Location History for Demo User (id=1 or id=47 depending on your setup)
-- Includes realistic locations with timestamps

-- Query to find your demo user ID:
-- SELECT id, username, email FROM users WHERE username='demo' OR email='demo@example.com' LIMIT 1;

-- Sample locations representing different places and times
-- Adjust the user_id (1) to match your actual demo user ID

-- Home Location (Florida, Orlando area)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5383, -81.3792, 15, 'browser', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- Office/Workplace (Downtown Orlando)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5421, -81.3723, 12, 'browser', DATE_SUB(NOW(), INTERVAL 4 DAY));

-- Coffee Shop (Winter Park area)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5945, -81.3562, 8, 'browser', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- Shopping Center (Millenia)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5166, -81.3836, 20, 'browser', DATE_SUB(NOW(), INTERVAL 2 DAY));

-- Home (evening)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5383, -81.3792, 10, 'browser', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- Office (morning)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5421, -81.3723, 9, 'browser', DATE_SUB(NOW(), INTERVAL 12 HOUR));

-- Park/Recreation (Lake Eustis area)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.7452, -81.7365, 25, 'device', DATE_SUB(NOW(), INTERVAL 8 HOUR));

-- Home (current/recent)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5383, -81.3792, 7, 'browser', DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- Additional realistic Florida locations for comprehensive history
-- Beach location (Daytona Beach)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 29.2108, -80.9401, 35, 'device', DATE_SUB(NOW(), INTERVAL 8 DAY));

-- Theme Park area (Universal/Disney proximity)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.4756, -81.4670, 50, 'browser', DATE_SUB(NOW(), INTERVAL 6 DAY));

-- Airport location (MCO)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.4312, -81.3088, 100, 'device', DATE_SUB(NOW(), INTERVAL 10 DAY));

-- Gym/Fitness (near home)
INSERT INTO `location_logs` (user_id, lat, lon, accuracy_m, source, created_at) 
VALUES (1, 28.5400, -81.3810, 11, 'browser', DATE_SUB(NOW(), INTERVAL 7 HOUR));
