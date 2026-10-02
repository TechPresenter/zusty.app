-- ---------------------------------------------------------------------
-- 023 — KYC detail blocks must be creatable empty
--
-- `kyc_brand_details.company_name` and `kyc_creator_details.full_name` are
-- NOT NULL with no default. The KYC forms autosave section by section, so the
-- very first save — of, say, only the address block — tries to INSERT a row
-- without those columns and fails:
--
--   SQLSTATE[HY000]: 1364 Field 'full_name' doesn't have a default value
--
-- A draft is allowed to be incomplete; completeness is judged at submission
-- time by KycService::hasRequiredDetails(), which is where the real check
-- belongs. Giving these columns an empty default lets a draft exist without
-- weakening the submit gate.
-- ---------------------------------------------------------------------

ALTER TABLE `kyc_brand_details`
  MODIFY COLUMN `company_name` VARCHAR(191) NOT NULL DEFAULT '';

ALTER TABLE `kyc_creator_details`
  MODIFY COLUMN `full_name` VARCHAR(150) NOT NULL DEFAULT '';
