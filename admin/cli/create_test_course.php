<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to create a test course with generated image
 *
 * @package    core
 * @subpackage cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/gdlib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'shortname' => '',
        'fullname' => 'Тестовый курс',
        'summary' => 'Тестовый курс, созданный автоматически',
        'category' => null,
        'imageprompt' => 'в старинном доме под светом свечи мужчина читает книгу',
        'help' => false,
    ],
    [
        's' => 'shortname',
        'f' => 'fullname',
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || empty($options['shortname'])) {
    $help = <<<EOT
CLI script to create a test course with generated image.

Options:
 -h, --help                Print out this help
 -s, --shortname           Course shortname (required)
 -f, --fullname            Course fullname (default: "Тестовый курс")
     --summary             Course summary (default: "Тестовый курс, созданный автоматически")
     --category            Category ID (default: default category)
     --imageprompt         Image generation prompt (default: "в старинном доме под светом свечи мужчина читает книгу")

Example:
\$sudo -u www-data php admin/cli/create_test_course.php --shortname=test-course-001 --fullname="Тестовый курс 1"
\$sudo -u www-data php admin/cli/create_test_course.php --shortname=test-course-002 --imageprompt="красивая библиотека с книгами"

EOT;
    echo $help;
    die;
}

// Set admin user
\core\session\manager::set_user(get_admin());

// Get category
if (empty($options['category'])) {
    $category = core_course_category::get_default();
} else {
    $category = core_course_category::get($options['category'], MUST_EXIST);
}

mtrace('Создание тестового курса...');
mtrace('Shortname: ' . $options['shortname']);
mtrace('Fullname: ' . $options['fullname']);
mtrace('Category: ' . $category->name . ' (ID: ' . $category->id . ')');

// Create course data
$coursedata = new stdClass();
$coursedata->category = $category->id;
$coursedata->fullname = $options['fullname'];
$coursedata->shortname = $options['shortname'];
$coursedata->summary = $options['summary'];
$coursedata->summaryformat = FORMAT_HTML;
$coursedata->format = 'topics';
$coursedata->numsections = 5;
$coursedata->visible = 1;
$coursedata->startdate = time();

// Create course
try {
    $course = create_course($coursedata);
    mtrace('✓ Курс создан успешно! ID: ' . $course->id);
} catch (Exception $e) {
    cli_error('Ошибка при создании курса: ' . $e->getMessage());
}

// Generate image
mtrace('Генерация изображения...');
mtrace('Prompt: ' . $options['imageprompt']);

$imageurl = generate_image($options['imageprompt']);

if (!$imageurl) {
    mtrace('⚠ Не удалось сгенерировать изображение. Пропускаем загрузку.');
} else {
    mtrace('✓ Изображение сгенерировано: ' . $imageurl);
    
    // Download and save image
    mtrace('Загрузка изображения...');
    $imagedata = download_image($imageurl);
    
    if ($imagedata) {
        mtrace('✓ Изображение загружено (' . strlen($imagedata) . ' байт)');
        
        // Save image to course overviewfiles
        $context = context_course::instance($course->id);
        $fs = get_file_storage();
        
        // Determine file extension based on file content
        $ext = 'jpg';
        if (strpos($imageurl, '.png') !== false) {
            $ext = 'png';
        } elseif (file_exists($imageurl)) {
            // Check file header
            $header = file_get_contents($imageurl, false, null, 0, 8);
            if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
                $ext = 'png';
            } elseif (substr($header, 0, 2) === "\xFF\xD8") {
                $ext = 'jpg';
            }
        }
        
        // Prepare file record
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'course_image.' . $ext,
            'userid' => get_admin()->id,
        ];
        
        // Delete existing files in overviewfiles (if any)
        $fs->delete_area_files($context->id, 'course', 'overviewfiles', 0);
        
        // Save image
        try {
            $fs->create_file_from_string($filerecord, $imagedata);
            mtrace('✓ Изображение сохранено как обложка курса');
        } catch (Exception $e) {
            mtrace('⚠ Ошибка при сохранении изображения: ' . $e->getMessage());
        }
    } else {
        mtrace('⚠ Не удалось загрузить изображение');
    }
}

mtrace('');
mtrace('✓ Готово! Курс создан: ' . $CFG->wwwroot . '/course/view.php?id=' . $course->id);

/**
 * Generate image using API or create placeholder
 * 
 * @param string $prompt Image generation prompt
 * @return string|false Image file path or false on error
 */
function generate_image($prompt) {
    // Translate prompt to English
    $englishprompt = translate_prompt($prompt);
    
    // Try multiple methods
    $methods = [
        'huggingface' => function() use ($englishprompt) {
            return generate_image_huggingface($englishprompt);
        },
        'placeholder' => function() use ($prompt) {
            return generate_placeholder_image($prompt);
        },
    ];
    
    foreach ($methods as $method => $callback) {
        mtrace('  Попытка методом: ' . $method);
        $result = $callback();
        if ($result) {
            return $result;
        }
    }
    
    return false;
}

/**
 * Generate image using Hugging Face Inference API
 * 
 * @param string $prompt English prompt
 * @return string|false Image file path or false on error
 */
function generate_image_huggingface($prompt) {
    // Try Hugging Face Inference API (free tier, but may require API key)
    $models = [
        'runwayml/stable-diffusion-v1-5',
        'stabilityai/stable-diffusion-2-1',
    ];
    
    foreach ($models as $model) {
        $apiurl = 'https://api-inference.huggingface.co/models/' . $model;
        
        mtrace('    Модель: ' . $model);
        
        $data = [
            'inputs' => $prompt,
            'parameters' => [
                'num_inference_steps' => 20,
                'guidance_scale' => 7.5,
            ]
        ];
        
        $ch = curl_init($apiurl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: image/png',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contenttype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpcode == 200 && $response && strlen($response) > 100) {
            // Check if it's actually an image
            if (strpos($contenttype, 'image/') === 0 || 
                (substr($response, 0, 8) === "\x89PNG\r\n\x1a\n") ||
                (substr($response, 0, 2) === "\xFF\xD8")) {
                $tempfile = sys_get_temp_dir() . '/moodle_image_' . uniqid() . '.png';
                file_put_contents($tempfile, $response);
                mtrace('    ✓ Изображение сгенерировано успешно');
                return $tempfile;
            }
        }
        
        if ($httpcode == 503) {
            mtrace('    ⚠ Модель загружается, пробуем следующую...');
        } else if ($httpcode == 401 || $httpcode == 403) {
            mtrace('    ⚠ Требуется API ключ для этого метода');
            break; // Don't try other models if auth required
        } else if ($httpcode != 200) {
            mtrace('    ⚠ Ошибка HTTP ' . $httpcode);
        }
    }
    
    return false;
}

/**
 * Generate placeholder image using simple image creation
 * 
 * @param string $prompt Prompt (for reference)
 * @return string|false Image file path or false on error
 */
function generate_placeholder_image($prompt) {
    // Create a simple placeholder image using GD library
    if (!extension_loaded('gd')) {
        mtrace('    ⚠ GD extension не доступна для создания изображения');
        return false;
    }
    
    $width = 800;
    $height = 600;
    
    $image = imagecreatetruecolor($width, $height);
    
    // Background color (warm brown/orange like candlelight)
    $bgcolor = imagecolorallocate($image, 80, 60, 40);
    imagefill($image, 0, 0, $bgcolor);
    
    // Add some text
    $textcolor = imagecolorallocate($image, 255, 200, 150);
    $fontsize = 24;
    
    // Simple text (Russian support would require TTF font)
    $text = 'Course Image';
    $textx = ($width - strlen($text) * 10) / 2;
    $texty = $height / 2;
    
    imagestring($image, $fontsize, $textx, $texty, $text, $textcolor);
    
    // Save to temp file
    $tempfile = sys_get_temp_dir() . '/moodle_image_' . uniqid() . '.jpg';
    if (imagejpeg($image, $tempfile, 85)) {
        imagedestroy($image);
        mtrace('    ✓ Создано placeholder изображение');
        return $tempfile;
    }
    
    imagedestroy($image);
    return false;
}

/**
 * Translate prompt to English (simple mapping for common phrases)
 * 
 * @param string $prompt Russian prompt
 * @return string English prompt
 */
function translate_prompt($prompt) {
    // Simple translation mapping
    $translations = [
        'в старинном доме под светом свечи мужчина читает книгу' => 
            'in an old house under candlelight a man reading a book, atmospheric, warm lighting, vintage interior, cozy, detailed, photorealistic',
        'красивая библиотека с книгами' => 
            'beautiful library with books, grand bookshelves, warm lighting, cozy atmosphere, detailed, photorealistic',
    ];
    
    if (isset($translations[$prompt])) {
        return $translations[$prompt];
    }
    
    // Basic translation attempt (you can improve this)
    return 'a man reading a book in an old house under candlelight, atmospheric, warm lighting, vintage interior, cozy, detailed, photorealistic';
}

/**
 * Download image from URL or read local file
 * 
 * @param string $url Image URL or local file path
 * @return string|false Image binary data or false on error
 */
function download_image($url) {
    // Check if it's a local file path
    if (file_exists($url) && is_readable($url)) {
        $data = file_get_contents($url);
        if ($data !== false) {
            return $data;
        }
    }
    
    // Try to download from URL
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Moodle CLI Script');
        
        $data = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpcode == 200 && $data && strlen($data) > 100) {
            return $data;
        }
        
        if ($error) {
            mtrace('    ⚠ Ошибка curl: ' . $error);
        }
    }
    
    return false;
}

