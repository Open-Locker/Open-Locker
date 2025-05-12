<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Locker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File; // Use File facade for path manipulation
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str; // Use Str for slug

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    // Define the items and their corresponding source image assumptions
    // Assumes images are in database/seeders/assets/images/ with extensions like .jpg, .png, .jpeg
    protected $availableItems = [
        'Backpack', 'Laptop', 'Textbook', 'Gym Bag', 'Jacket', 'Umbrella',
        'Lunch Box', 'Notebook', 'Calculator', 'Headphones', 'Water Bottle',
        'Sports Equipment', 'School Supplies', 'Running Shoes',
        'Tablet', 'Phone Charger', 'Safety Helmet', 'Mini PC', 'Tool Box'
    ];

    // Relative path within the public disk where images will be stored
    protected string $publicImageDir = 'items';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Select a unique item name for this seeding run
        $itemName = $this->faker->unique()->randomElement($this->availableItems);

        return [
            'name' => $itemName,
            'description' => fake()->text(100), // Keep description concise
            'image_path' => null, // Will be set in afterCreating
            'locker_id' => Locker::factory(), // Let Laravel handle creation or use existing
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Item $item) {
            $this->copyImageAndUpdatePath($item);
        });
    }

    /**
     * Find the source image, copy it to public storage if needed, and update the item's path.
     */
    protected function copyImageAndUpdatePath(Item $item): void
    {
        $sourceDir = database_path('seeders/assets/images');
        $targetDisk = Storage::disk('public');
        // $possibleExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // Removed as we assume .jpg
        $sourceFile = null;

        // --- Simplified Search: Assume slugified filename + .jpg extension ---
        $baseName = $item->name; // e.g., "Phone Charger"
        $expectedSlugBase = Str::slug($baseName); // e.g., "phone-charger"
        $expectedSourcePath = $sourceDir . '/' . $expectedSlugBase . '.jpg';

        // Check if the specific .jpg file exists (case-insensitive check for robustness)
        // Note: File::exists might be case-sensitive on some systems. A more robust check:
        $foundPath = null;
        if (File::isDirectory($sourceDir)) {
            $files = glob($sourceDir . '/' . $expectedSlugBase . '.{jpg,JPG}', GLOB_BRACE | GLOB_NOSORT);
            if (!empty($files)) {
                // Filter potential results from glob to ensure it's the exact base name case-insensitively
                foreach ($files as $file) {
                     if (File::isFile($file) && strcasecmp(pathinfo($file, PATHINFO_FILENAME), $expectedSlugBase) === 0) {
                          $foundPath = $file;
                          break;
                     }
                }
            }
        }

        if ($foundPath) {
            $sourceFile = $foundPath;
        } else {
            Log::warning("Source image not found for item '{$item->name}'. Expected: '{$expectedSourcePath}'");
            return; // No image found for this item
        }
        // --- End of search ---

        if (!$sourceFile) { // This check is technically redundant now but kept for clarity
            // Log::warning already happened above
            return; 
        }

        // Determine target filename (slug + .jpg)
        $targetFilename = $expectedSlugBase . '.jpg';
        $relativeTargetPath = $this->publicImageDir . '/' . $targetFilename;

        try {
            // Ensure the target directory exists
            if (!$targetDisk->exists($this->publicImageDir)) {
                $targetDisk->makeDirectory($this->publicImageDir);
            }

            // Copy the file only if it doesn't already exist in the public directory
            if (!$targetDisk->exists($relativeTargetPath)) {
                 // Check read permissions before copying
                 if (!is_readable($sourceFile)) {
                     Log::error("Source image file is not readable: {$sourceFile}");
                     return;
                 }
                $fileContent = file_get_contents($sourceFile);
                if ($fileContent === false) {
                    Log::error("Could not read source image file: {$sourceFile}");
                    return;
                }
                if (!$targetDisk->put($relativeTargetPath, $fileContent)) {
                     Log::error("Failed to copy image to public storage: {$relativeTargetPath}");
                     return; // Stop if copy fails
                }
                Log::info("Copied image for item '{$item->name}' to {$relativeTargetPath}");
            }

            // Update the item's image path if the file exists (either copied or previously existing)
             if ($targetDisk->exists($relativeTargetPath)) {
                $item->image_path = $relativeTargetPath;
                $item->save();
            } else {
                 Log::error("Target image file does not exist after potential copy operation: {$relativeTargetPath}");
            }

        } catch (\Throwable $e) {
            Log::error("Error processing image for item '{$item->name}': " . $e->getMessage(), ['exception' => $e]);
        }
    }
}
