-- Normalize deprecated Kyiv timezone spelling stored by older installs.
UPDATE users
SET display_tz = 'Europe/Kyiv'
WHERE display_tz = 'Europe/Kiev';

UPDATE ad_accounts
SET timezone_name = 'Europe/Kyiv'
WHERE timezone_name = 'Europe/Kiev';
