-- Add sales person code column to investors table
ALTER TABLE investors ADD COLUMN IF NOT EXISTS sales_person_code VARCHAR(50) NULL;
