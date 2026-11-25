<?php
declare(strict_types=1);

final class PublicContentService
{
    public static function load(): array
    {
        $pdo = Database::connection();
        $project = [
            'id' => null,
            'name' => '',
            'location' => '',
            'total_land_area' => null,
            'plot_count' => 0,
            'price_per_sqyd' => null,
            'status' => '',
            'description' => '',
            'brochure_path' => '',
            'map_embed_url' => '',
        ];
        $amenities = [];
        $plots = [];
        $gallery = [];
        $specifications = [];

        $projectStatement = $pdo->query(
            "SELECT p.id, p.name, p.location, p.total_land_area, p.price_per_sqyd, p.status,
                    p.description, p.brochure_path, p.map_embed_url,
                    (SELECT COUNT(*) FROM plots pl WHERE pl.project_id = p.id) AS plot_count
             FROM projects p
             WHERE p.status = 'published'
             ORDER BY p.id DESC
             LIMIT 1"
        );
        $row = $projectStatement->fetch();
        if ($row !== false) {
            $project = $row;
            $project['map_embed_url'] = self::safeMapUrl((string) ($row['map_embed_url'] ?? ''));
            $projectId = (int) $row['id'];

            $statement = $pdo->prepare(
                'SELECT name, description, image_path, display_order
                 FROM amenities
                 WHERE project_id = :project_id AND published = 1
                 ORDER BY display_order, id'
            );
            $statement->execute(['project_id' => $projectId]);
            $amenities = $statement->fetchAll();

            $statement = $pdo->prepare(
                "SELECT pl.plot_number, pl.dimensions, pl.area_sqyd, pl.facing_direction, pl.status, p.price_per_sqyd,
                        CASE
                          WHEN pl.area_sqyd IS NULL OR p.price_per_sqyd IS NULL THEN NULL
                          ELSE ROUND(pl.area_sqyd * p.price_per_sqyd, 2)
                        END AS total_price
                 FROM plots pl
                 JOIN projects p ON p.id = pl.project_id
                 WHERE pl.project_id = :project_id
                 ORDER BY CAST(pl.plot_number AS UNSIGNED), pl.plot_number"
            );
            $statement->execute(['project_id' => $projectId]);
            $plots = $statement->fetchAll();

            $statement = $pdo->prepare(
                'SELECT label, spec_value
                 FROM project_specifications
                 WHERE project_id = :project_id AND published = 1
                 ORDER BY display_order, id'
            );
            $statement->execute(['project_id' => $projectId]);
            $specifications = $statement->fetchAll();

            $statement = $pdo->prepare(
                'SELECT image_path, alt_text
                 FROM project_gallery
                 WHERE project_id = :project_id AND published = 1
                 ORDER BY display_order, id'
            );
            $statement->execute(['project_id' => $projectId]);
            $gallery = $statement->fetchAll();
        }

        return [
            'project' => $project,
            'amenities' => $amenities,
            'plots' => $plots,
            'specifications' => $specifications,
            'gallery' => $gallery,
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function safeMapUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), ['www.google.com', 'maps.google.com'], true)
        ) {
            return '';
        }

        return $url;
    }
}
