<?php
/**
 * Template for displaying Forminator uploaded images in a grid
 * Variables expected:
 * - $files: array of file info
 */

foreach ($files as $row) :
    if (empty($row['file']['file_url'])) continue;
    if (is_array($row['file']['file_url'])) :
        foreach ($row['file']['file_url'] as $img) :
            $url = esc_url($img);
            if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url)) continue;
            ?>
            <div class="forminator-image-item-wrapper" data-pharmacy="<?php echo $row['pharmacy'] ?? ''; ?>" data-brand="<?php echo $row['brand'] ?? ''; ?>">
                <a href="<?php echo $url; ?>" data-lightbox="forminator-gallery" class="forminator-lightbox-item">
                    <img src="<?php echo $url; ?>" alt="" loading="lazy" />
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endforeach; ?>