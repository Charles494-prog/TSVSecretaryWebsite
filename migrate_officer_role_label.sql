USE tsv_secretary;

UPDATE officers
SET tungkulin = 'Locale TSV Officer'
WHERE tungkulin IN ('TSV Officers', 'Locale TSV Officer ng Officers');
