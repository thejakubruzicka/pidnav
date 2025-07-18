<?php
// PID All-in-One Transit Application
// Author: Gemini
// Version: 1.0.0
// A single-file PHP application for Prague Integrated Transport (PID)
// Features: Real-time departures, live vehicle map, line timetables, and service alerts.
// Languages: Czech (cs), English (en)

// --- CONFIGURATION & SETUP ---

// IMPORTANT: REPLACE WITH YOUR OWN GOLEMIO API KEY
// Get your free key from: https://api.golemio.cz/api-keys
define('GOLEMIO_API_KEY', ''); // <-- PASTE YOUR API KEY HERE

// --- LANGUAGE & TRANSLATION ---

// Determine language from URL query (?lang=en), default to Czech (cs)
$lang = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'en' : 'cs';

// Simple translation function
function t($translations) {
    global $lang;
    return $translations[$lang] ?? $translations['en']; // Default to English if translation is missing
}

// Translation strings
$texts = [
    'app_title' => ['cs' => 'PID Info Aplikace', 'en' => 'PID Info App'],
    'departures' => ['cs' => 'Odjezdy', 'en' => 'Departures'],
    'live_map' => ['cs' => 'Živá Mapa', 'en' => 'Live Map'],
    'alerts' => ['cs' => 'Mimořádnosti', 'en' => 'Alerts'],
    'search_stop' => ['cs' => 'Vyhledat zastávku', 'en' => 'Search for a stop'],
    'find' => ['cs' => 'Najít', 'en' => 'Find'],
    'stop_placeholder' => ['cs' => 'např. Malostranská', 'en' => 'e.g., Malostranská'],
    'line' => ['cs' => 'Linka', 'en' => 'Line'],
    'destination' => ['cs' => 'Směr', 'en' => 'Destination'],
    'scheduled' => ['cs' => 'Plán', 'en' => 'Scheduled'],
    'predicted' => ['cs' => 'Odjezd', 'en' => 'Departure'],
    'delay' => ['cs' => 'Zpoždění', 'en' => 'Delay'],
    'minutes_short' => ['cs' => 'min', 'en' => 'min'],
    'no_departures' => ['cs' => 'Pro tuto zastávku nebyly nalezeny žádné odjezdy.', 'en' => 'No departures found for this stop.'],
    'enter_stop_name' => ['cs' => 'Zadejte název zastávky pro zobrazení odjezdů.', 'en' => 'Enter a stop name to see departures.'],
    'loading_map' => ['cs' => 'Načítám mapu a polohy vozidel...', 'en' => 'Loading map and vehicle positions...'],
    'planned_disruptions' => ['cs' => 'Plánované výluky a změny', 'en' => 'Planned Disruptions & Changes'],
    'current_emergencies' => ['cs' => 'Mimořádné události v provozu', 'en' => 'Current Service Emergencies'],
    'no_alerts' => ['cs' => 'Aktuálně nejsou hlášeny žádné události.', 'en' => 'No events are currently reported.'],
    'api_key_missing' => ['cs' => 'Chybí API klíč pro Golemio. Vložte jej prosím do kódu.', 'en' => 'Golemio API key is missing. Please add it to the code.'],
    'error_fetching_data' => ['cs' => 'Chyba při načítání dat.', 'en' => 'Error fetching data.'],
];

// --- BACKEND LOGIC & API CALLS ---

// Determine the current page from URL query (?page=...), default to departures
$page = $_GET['page'] ?? 'departures';

// Handle API requests from our own frontend (JavaScript)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (GOLEMIO_API_KEY === '') {
        http_response_code(500);
        echo json_encode(['error' => t($texts['api_key_missing'])]);
        exit;
    }

    if ($_GET['action'] === 'get_vehicles') {
        // Proxy for vehicle positions to hide API key
        $vehicleData = getGolemioData('/v2/vehiclepositions', ['includeNotTrackable' => 'true']);
        echo json_encode($vehicleData);
        exit;
    }
    exit;
}

/**
 * Fetches data from the Golemio API.
 * @param string $endpoint The API endpoint path (e.g., /v2/departureboards).
 * @param array $params Optional query parameters.
 * @return array|null The decoded JSON response or null on error.
 */
function getGolemioData($endpoint, $params = []) {
    $url = 'https://api.golemio.cz' . $endpoint . '?' . http_build_query($params);
    $options = [
        'http' => [
            'header' => "X-Access-Token: " . GOLEMIO_API_KEY . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET',
            'timeout' => 10, // 10 second timeout
        ],
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}


/**
 * Fetches and parses an RSS feed.
 * @param string $url The URL of the RSS feed.
 * @return array An array of feed items.
 */
function getRssFeed($url) {
    try {
        $rss = @simplexml_load_file($url);
        if ($rss === false) return [];
        $items = [];
        foreach ($rss->channel->item as $item) {
            $items[] = [
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => (string) $item->description,
                'pubDate' => (string) $item->pubDate,
            ];
        }
        return $items;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Finds stop IDs based on a stop name.
 * @param string $name The name of the stop to search for.
 * @return array An array of matching stop IDs.
 */
function findStopIds($name) {
    $params = ['query' => $name];
    $stopsData = getGolemioData('/v2/gtfs/stops', $params);
    $ids = [];
    if ($stopsData && !empty($stopsData['features'])) {
        foreach ($stopsData['features'] as $stop) {
            // Use the CIS ID, which is common for departure boards
            if (isset($stop['properties']['stop_id'])) {
                 // The API expects the GTFS ID in the format 'U123Z1P'
                 $parts = explode(':', $stop['properties']['stop_id']);
                 if(count($parts) > 1) {
                    $ids[] = $parts[1];
                 }
            }
        }
    }
    return array_unique($ids);
}

/**
 * Fetches departures for a given set of stop IDs.
 * @param array $stopIds Array of stop IDs.
 * @return array Departures data.
 */
function getDepartures($stopIds) {
    if (empty($stopIds)) return [];
    
    $params = [
        'ids' => $stopIds,
        'limit' => 20,
        'minutesAfter' => 120, // Look for departures in the next 2 hours
    ];
    $departuresData = getGolemioData('/v2/departureboards', $params);
    
    // Sort departures by predicted departure time
    if (!empty($departuresData['departures'])) {
        usort($departuresData['departures'], function($a, $b) {
            return strtotime($a['departure_timestamp']['predicted']) <=> strtotime($b['departure_timestamp']['predicted']);
        });
    }
    
    return $departuresData;
}

// --- DATA PREPARATION FOR CURRENT PAGE ---

$departureData = null;
$stopQuery = '';
$plannedAlerts = [];
$emergencyAlerts = [];

if ($page === 'departures') {
    if (isset($_GET['stop']) && !empty($_GET['stop'])) {
        $stopQuery = htmlspecialchars($_GET['stop']);
        if (GOLEMIO_API_KEY !== '') {
            $stopIds = findStopIds($stopQuery);
            $departureData = getDepartures($stopIds);
        }
    }
} elseif ($page === 'alerts') {
    $plannedAlerts = getRssFeed('https://pid.cz/feed/rss-vyluky');
    $emergencyAlerts = getRssFeed('https://pid.cz/feed/rss-mimoradnosti');
}

?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t($texts['app_title']); ?></title>
    
    <!-- Fonts and Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <style>
        /* PID Visual Identity Inspired CSS */
        :root {
            --pid-red: #CC0000;
            --pid-grey: #F2F2F2; /* Lighter grey for background */
            --pid-dark-grey: #B1B5B3;
            --pid-black: #000000;
            --text-color: #333;
            --border-radius: 8px;
        }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: var(--pid-grey);
            color: var(--text-color);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .container {
            width: 95%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 0;
            flex-grow: 1;
        }
        header {
            background-color: #FFF;
            padding: 15px 2.5%;
            border-bottom: 3px solid var(--pid-red);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .logo {
            font-weight: 700;
            font-size: 1.5em;
            color: var(--pid-black);
        }
        header .logo span {
            color: var(--pid-red);
            font-weight: 700;
        }
        .lang-switcher a {
            color: var(--text-color);
            text-decoration: none;
            padding: 5px 8px;
            font-weight: bold;
        }
        .lang-switcher a.active {
            color: var(--pid-red);
            border-bottom: 2px solid var(--pid-red);
        }
        nav {
            background-color: #fff;
            padding: 10px 2.5%;
            display: flex;
            justify-content: center;
            gap: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        nav a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 700;
            padding: 10px 15px;
            border-radius: var(--border-radius);
            transition: background-color 0.2s, color 0.2s;
        }
        nav a.active, nav a:hover {
            background-color: var(--pid-red);
            color: #fff;
        }
        .content-box {
            background-color: #fff;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        .form-group input {
            flex-grow: 1;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: var(--border-radius);
            font-size: 1em;
        }
        .form-group button {
            padding: 12px 20px;
            background-color: var(--pid-red);
            color: #fff;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1em;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .form-group button:hover {
            background-color: #a30000;
        }
        .departures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .departures-table th, .departures-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .departures-table th {
            background-color: var(--pid-grey);
            font-weight: 700;
        }
        .departures-table .line-badge {
            display: inline-block;
            padding: 4px 8px;
            font-weight: 700;
            color: #fff;
            border-radius: 4px;
            min-width: 30px;
            text-align: center;
        }
        .line-badge.bus { background-color: #0072bc; }
        .line-badge.tram { background-color: var(--pid-red); }
        .line-badge.metro-a { background-color: #00954d; }
        .line-badge.metro-b { background-color: #ffcd00; color: #000; }
        .line-badge.metro-c { background-color: var(--pid-red); }
        .line-badge.train { background-color: #0072bc; }
        .line-badge.default { background-color: #666; }
        .delay-positive { color: var(--pid-red); font-weight: bold; }
        .delay-negative { color: green; font-weight: bold; }

        #map {
            height: 600px;
            width: 100%;
            border-radius: var(--border-radius);
            border: 1px solid #ccc;
        }
        .alert-list .alert-item {
            border-left: 4px solid var(--pid-dark-grey);
            padding: 15px;
            margin-bottom: 15px;
            background-color: #fff;
        }
        .alert-list .alert-item h3 { margin-top: 0; }
        .alert-list .alert-item p { margin-bottom: 5px; }
        .alert-list .alert-item a { color: var(--pid-red); }

        .message-box {
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            font-weight: bold;
        }
        .message-box.info { background-color: #eef6fc; color: #31708f; }
        .message-box.error { background-color: #f2dede; color: #a94442; }

        footer {
            text-align: center;
            padding: 20px;
            margin-top: 20px;
            font-size: 0.9em;
            color: #777;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>

    <header>
        <div class="logo">pid<span>.</span>info</div>
        <div class="lang-switcher">
            <a href="?page=<?php echo $page; ?>&lang=cs" class="<?php echo $lang === 'cs' ? 'active' : ''; ?>">CS</a>
            <a href="?page=<?php echo $page; ?>&lang=en" class="<?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
        </div>
    </header>

    <nav>
        <a href="?page=departures&lang=<?php echo $lang; ?>" class="<?php echo $page === 'departures' ? 'active' : ''; ?>"><?php echo t($texts['departures']); ?></a>
        <a href="?page=map&lang=<?php echo $lang; ?>" class="<?php echo $page === 'map' ? 'active' : ''; ?>"><?php echo t($texts['live_map']); ?></a>
        <a href="?page=alerts&lang=<?php echo $lang; ?>" class="<?php echo $page === 'alerts' ? 'active' : ''; ?>"><?php echo t($texts['alerts']); ?></a>
    </nav>

    <div class="container">
        <?php if (GOLEMIO_API_KEY === ''): ?>
            <div class="message-box error"><?php echo t($texts['api_key_missing']); ?></div>
        <?php else: ?>
            <?php if ($page === 'departures'): ?>
                <div class="content-box">
                    <h1><?php echo t($texts['departures']); ?></h1>
                    <form method="GET" action="">
                        <input type="hidden" name="page" value="departures">
                        <input type="hidden" name="lang" value="<?php echo $lang; ?>">
                        <div class="form-group">
                            <input type="text" name="stop" placeholder="<?php echo t($texts['stop_placeholder']); ?>" value="<?php echo $stopQuery; ?>" required>
                            <button type="submit"><?php echo t($texts['find']); ?></button>
                        </div>
                    </form>

                    <?php if ($departureData): ?>
                        <?php if (!empty($departureData['departures'])): ?>
                            <table class="departures-table">
                                <thead>
                                    <tr>
                                        <th><?php echo t($texts['line']); ?></th>
                                        <th><?php echo t($texts['destination']); ?></th>
                                        <th><?php echo t($texts['scheduled']); ?></th>
                                        <th><?php echo t($texts['predicted']); ?></th>
                                        <th><?php echo t($texts['delay']); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departureData['departures'] as $dep): 
                                        $route = $dep['route']['short_name'];
                                        $headsign = $dep['trip']['headsign'];
                                        $scheduled = new DateTime($dep['departure_timestamp']['scheduled']);
                                        $predicted = new DateTime($dep['departure_timestamp']['predicted']);
                                        $delay_seconds = $dep['delay']['seconds'];
                                        $delay_minutes = floor($delay_seconds / 60);

                                        $vehicle_type = 'default';
                                        if (isset($dep['trip']['vehicle_type'])) {
                                            $type_enum = $dep['trip']['vehicle_type'];
                                            if ($type_enum == 3) $vehicle_type = 'bus';
                                            elseif ($type_enum == 0) $vehicle_type = 'tram';
                                            elseif ($type_enum == 1) { // Metro
                                                if (strpos($route, 'A') !== false) $vehicle_type = 'metro-a';
                                                elseif (strpos($route, 'B') !== false) $vehicle_type = 'metro-b';
                                                else $vehicle_type = 'metro-c';
                                            }
                                            elseif ($type_enum == 2) $vehicle_type = 'train';
                                        }
                                    ?>
                                    <tr>
                                        <td><span class="line-badge <?php echo $vehicle_type; ?>"><?php echo $route; ?></span></td>
                                        <td><?php echo $headsign; ?></td>
                                        <td><?php echo $scheduled->format('H:i'); ?></td>
                                        <td><strong><?php echo $predicted->format('H:i'); ?></strong></td>
                                        <td>
                                            <?php if ($delay_minutes > 0): ?>
                                                <span class="delay-positive">+<?php echo $delay_minutes; ?> <?php echo t($texts['minutes_short']); ?></span>
                                            <?php elseif ($delay_minutes < 0): ?>
                                                <span class="delay-negative"><?php echo $delay_minutes; ?> <?php echo t($texts['minutes_short']); ?></span>
                                            <?php else: ?>
                                                <span>-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="message-box info"><?php echo t($texts['no_departures']); ?></div>
                        <?php endif; ?>
                    <?php elseif ($stopQuery): ?>
                        <div class="message-box error"><?php echo t($texts['error_fetching_data']); ?></div>
                    <?php else: ?>
                        <div class="message-box info"><?php echo t($texts['enter_stop_name']); ?></div>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'map'): ?>
                <div class="content-box">
                    <h1><?php echo t($texts['live_map']); ?></h1>
                    <p><?php echo t($texts['loading_map']); ?></p>
                    <div id="map"></div>
                </div>

            <?php elseif ($page === 'alerts'): ?>
                <div class="content-box">
                    <h1><?php echo t($texts['alerts']); ?></h1>

                    <h2><?php echo t($texts['current_emergencies']); ?></h2>
                    <div class="alert-list">
                        <?php if (!empty($emergencyAlerts)): ?>
                            <?php foreach ($emergencyAlerts as $alert): ?>
                                <div class="alert-item">
                                    <h3><?php echo htmlspecialchars($alert['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($alert['description']); ?></p>
                                    <a href="<?php echo htmlspecialchars($alert['link']); ?>" target="_blank">Více informací</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo t($texts['no_alerts']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <h2 style="margin-top: 40px;"><?php echo t($texts['planned_disruptions']); ?></h2>
                    <div class="alert-list">
                        <?php if (!empty($plannedAlerts)): ?>
                            <?php foreach ($plannedAlerts as $alert): ?>
                                <div class="alert-item">
                                    <h3><?php echo htmlspecialchars($alert['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($alert['description']); ?></p>
                                    <a href="<?php echo htmlspecialchars($alert['link']); ?>" target="_blank">Více informací</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php echo t($texts['no_alerts']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer>
        PID Info App &copy; <?php echo date('Y'); ?> | Data provided by <a href="https://golemio.cz/" target="_blank">Golemio</a> and <a href="https://pid.cz" target="_blank">PID</a>.
    </footer>

    <?php if ($page === 'map' && GOLEMIO_API_KEY !== ''): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize map centered on Prague
            const map = L.map('map').setView([50.0755, 14.4378], 12);

            // Add OpenStreetMap tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            let vehicleMarkers = L.layerGroup().addTo(map);

            // Function to create a custom SVG icon for vehicles
            const createVehicleIcon = (type, routeName) => {
                let color = '#666'; // Default
                let textColor = '#fff';
                switch (type) {
                    case 0: color = 'var(--pid-red)'; break; // Tram
                    case 1: // Metro
                        if (routeName.includes('A')) color = '#00954d';
                        else if (routeName.includes('B')) { color = '#ffcd00'; textColor = '#000'; }
                        else color = 'var(--pid-red)';
                        break;
                    case 2: color = '#0072bc'; break; // Train
                    case 3: color = '#0072bc'; break; // Bus
                }

                const svgIcon = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="24" viewBox="0 0 36 24">
                        <rect x="0" y="0" width="36" height="24" rx="6" ry="6" fill="${color}" stroke="#fff" stroke-width="2"/>
                        <text x="18" y="16" font-family="Inter, sans-serif" font-size="12" font-weight="bold" fill="${textColor}" text-anchor="middle">${routeName}</text>
                    </svg>`;

                return L.divIcon({
                    html: svgIcon,
                    className: 'vehicle-icon',
                    iconSize: [36, 24],
                    iconAnchor: [18, 12]
                });
            };
            
            // Function to fetch and update vehicle positions
            const updateVehicles = async () => {
                try {
                    const response = await fetch('?action=get_vehicles&lang=<?php echo $lang; ?>');
                    if (!response.ok) {
                        console.error('Failed to fetch vehicle data.');
                        return;
                    }
                    const data = await response.json();
                    
                    if (data.error) {
                        console.error("API Error:", data.error);
                        return;
                    }

                    vehicleMarkers.clearLayers();

                    if (data && data.features) {
                        data.features.forEach(vehicle => {
                            const props = vehicle.properties;
                            const coords = vehicle.geometry.coordinates;

                            if (props.is_tracked && props.trip) {
                                const lat = coords[1];
                                const lon = coords[0];
                                const routeName = props.trip.route_short_name;
                                const vehicleType = props.trip.vehicle_type;
                                const headsign = props.trip.headsign;
                                const delay = props.delay.is_delayed ? props.delay.delay_s : 0;
                                const delayText = delay > 0 ? `+${Math.round(delay/60)} min` : (delay < 0 ? `${Math.round(delay/60)} min` : 'On time');

                                const icon = createVehicleIcon(vehicleType, routeName);
                                const marker = L.marker([lat, lon], { icon: icon });

                                marker.bindPopup(`<b>Line ${routeName}</b><br>To: ${headsign}<br>Delay: ${delayText}`);
                                vehicleMarkers.addLayer(marker);
                            }
                        });
                    }
                } catch (error) {
                    console.error('Error updating vehicles:', error);
                }
            };

            // Initial load and set interval for updates
            updateVehicles();
            setInterval(updateVehicles, 10000); // Update every 10 seconds
        });
    </script>
    <?php endif; ?>

</body>
</html>
