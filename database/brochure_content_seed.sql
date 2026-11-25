-- Brochure-backed public content for Quantum City @ Lam. No plot inventory is created.
-- Safe to re-run: existing project content is left unchanged.
START TRANSACTION;

INSERT INTO projects
  (name, slug, location, total_land_area, plot_count, price_per_sqyd, status, description, map_embed_url)
VALUES
  (
    'Quantum City @ Lam',
    'quantum-city',
    'Lam Village, Behind Delhi Public School, Tadikonda (M) - 522034',
    0,
    0,
    NULL,
    'published',
    'A Premium Residential Plot Development, proudly presented by Merit Real Solutions & EK Aram Developers. Location distances and listed features are reproduced from the supplied project brochure.',
    'https://www.google.com/maps?q=Behind+Delhi+Public+School%2C+Lam+Village%2C+Tadikonda%2C+Andhra+Pradesh+522034&output=embed'
  )
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @quantum_city_id = LAST_INSERT_ID();

INSERT INTO project_specifications (project_id, label, spec_value, display_order, published)
SELECT @quantum_city_id, spec.label, spec.spec_value, spec.display_order, 1
FROM (
  SELECT 'Location: Delhi Public School' AS label, '1 KM' AS spec_value, 10 AS display_order
  UNION ALL SELECT 'Location: Anantapur - Amaravathi Express Highway', '600 M', 20
  UNION ALL SELECT 'Location: Inner Ring Road (IRR)', '3 KM / 5 Min', 30
  UNION ALL SELECT 'Location: Outer Ring Road (ORR)', '15 Min Drive', 40
  UNION ALL SELECT 'Location: Tadikonda Railway Station', '4 KM / 5 Min', 50
  UNION ALL SELECT 'Location: Ravela International Airport', '7 KM / 5 Min', 60
  UNION ALL SELECT 'Location: AP Secretariat', '10 KM / 10 Min', 70
  UNION ALL SELECT 'Location: CM New House', '13.5 KM / 15 Min', 80
  UNION ALL SELECT 'Location: Guntur Bus Stand', '20 Min', 90
  UNION ALL SELECT 'Location: Guntur Railway Station', '15 Min', 100
  UNION ALL SELECT 'Location: VIT-AP University', '25 Min', 110
  UNION ALL SELECT 'Location: SRM University', '25 Min', 120
  UNION ALL SELECT 'Location: TTD Temple, Venkatayapalem', '30 Min', 130
  UNION ALL SELECT 'Highlight: 80 Ft, 40 Ft and 30 Ft Roads', 'Road widths listed in the supplied project brochure.', 200
  UNION ALL SELECT 'Highlight: Grand Entrance Arch', 'Entrance feature shown in the supplied project brochure.', 210
  UNION ALL SELECT 'Highlight: Avenue Plantation', 'Plantation feature listed in the supplied project brochure.', 220
  UNION ALL SELECT 'Highlight: Beautiful Parks', 'Park feature listed in the supplied project brochure.', 230
  UNION ALL SELECT 'Highlight: Children''s Play Area', 'Play-area feature listed in the supplied project brochure.', 240
  UNION ALL SELECT 'Highlight: Walking Tracks', 'Walking-track feature listed in the supplied project brochure.', 250
) AS spec
WHERE NOT EXISTS (
  SELECT 1
  FROM project_specifications existing
  WHERE existing.project_id = @quantum_city_id
    AND existing.label = spec.label
);

INSERT INTO amenities (project_id, name, description, display_order, published)
SELECT @quantum_city_id, amenity.name, amenity.description, amenity.display_order, 1
FROM (
  SELECT 'Wide internal roads' AS name, 'Road widths listed in the supplied brochure: 80 Ft, 40 Ft and 30 Ft.' AS description, 10 AS display_order
  UNION ALL SELECT 'Grand entrance arch', 'Entrance feature shown in the supplied project brochure.', 20
  UNION ALL SELECT 'Avenue plantation', 'Plantation feature listed in the supplied project brochure.', 30
  UNION ALL SELECT 'Landscaped parks', 'Park feature listed in the supplied project brochure.', 40
  UNION ALL SELECT 'Children''s play area', 'Play-area feature listed in the supplied project brochure.', 50
  UNION ALL SELECT 'Walking tracks', 'Walking-track feature listed in the supplied project brochure.', 60
  UNION ALL SELECT 'Family-friendly open spaces', 'Open-space illustration shown in the supplied project brochure.', 70
) AS amenity
WHERE NOT EXISTS (
  SELECT 1
  FROM amenities existing
  WHERE existing.project_id = @quantum_city_id
    AND existing.name = amenity.name
);

COMMIT;
