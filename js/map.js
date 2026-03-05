/**
 * Wetterdienst Eulenthal - Official Weather Service
 * Fürstentum Eulenthal · Meteorologische Landesanstalt
 * PHP Version 2.0 - Enhanced Features
 */

const API_BASE = 'api';

let mapConfig = null;
let stations = [];
let selectedStation = null;
let mapContainer = null;
let mapImage = null;
let markersContainer = null;
let transform = { x: 0, y: 0, scale: 1 };
let isDragging = false;
let dragStart = { x: 0, y: 0 };
let forecastCache = {};
let currentDayOffset = 0;
let animationInterval = null;
let animationHour = 0;
let isAnimating = false;

let tempCanvas = null;
let weatherCanvas = null;
let cloudCanvas = null;
let windCanvas = null;
let pressureCanvas = null;
let frontCanvas = null;
let overlayLayers = {
    temp: false,
    weather: false,
    clouds: true,
    wind: false,
    pressure: false,
    front: false
};

let dayWeatherCache = {};

const weatherStateTranslations = {
    'sunny': 'Sonnig',
    'clear': 'Klar',
    'partly_cloudy': 'Teilweise bewölkt',
    'cloudy': 'Bewölkt',
    'light_rain': 'Leichter Regen',
    'moderate_rain': 'Mäßiger Regen',
    'heavy_rain': 'Starker Regen',
    'clearing': 'Aufklarend',
    'snow': 'Schnee',
    'fog': 'Nebel'
};

function getWeatherIconClass(weatherState, hour = null) {
    if (hour === null) hour = new Date().getHours();
    const isNight = hour >= 21 || hour < 6;
    
    const iconMap = {
        sunny: isNight ? 'wi-night-clear' : 'wi-day-sunny',
        clear: 'wi-night-clear',
        partly_cloudy: isNight ? 'wi-night-alt-cloudy' : 'wi-day-cloudy',
        cloudy: 'wi-cloudy',
        light_rain: isNight ? 'wi-night-alt-rain' : 'wi-day-rain',
        moderate_rain: 'wi-rain',
        heavy_rain: 'wi-thunderstorm',
        clearing: isNight ? 'wi-night-alt-cloudy-high' : 'wi-day-cloudy-high',
        snow: 'wi-snow',
        fog: 'wi-fog'
    };
    
    return iconMap[weatherState] || 'wi-day-sunny';
}

function renderWeatherIcon(weatherState, hour = null) {
    const iconClass = getWeatherIconClass(weatherState, hour);
    return `<i class="wi ${iconClass}"></i>`;
}

function translateWeatherState(state) {
    if (!state) return 'Unbekannt';
    return weatherStateTranslations[state] || state.replace('_', ' ');
}

document.addEventListener('DOMContentLoaded', init);

function getLocalDateISO(date = new Date()) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function getDateForDayOffset(offset) {
    const date = new Date();
    date.setDate(date.getDate() + offset);
    return getLocalDateISO(date);
}

function getDayName(date) {
    const cmpDate = new Date(date);
    const days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    return days[cmpDate.getDay()];
}

function formatDayLabel(date, offset = 0) {
    if (offset === 0) return `Heute, ${getDayName(date)}`;
    if (offset === 1) return 'Morgen';
    return getDayName(date);
}

async function init() {
    try {
        setStatus('Lade Kartenkonfiguration...');
        
        const response = await fetchAPI(`${API_BASE}/map/config`);
        mapConfig = response;
        
        initMap();
        initDaySelector();
        initAnimationControls();
        
        await loadStations();
        await loadWarnings();
        
        setupEventListeners();
        setupViewToggle();
        
        setStatus('Bereit');
        updateLastUpdate();
        updateDateTime();
        
        setInterval(updateDateTime, 60000);
        
    } catch (error) {
        console.error('Initialization error:', error);
        setStatus('Fehler: ' + error.message);
    }
}

function initMap() {
    mapContainer = document.getElementById('map');
    
    mapContainer.innerHTML = `
        <div class="map-viewport">
            <div class="map-content" id="mapContent">
                <img src="${mapConfig.mapImage || 'maps/map.png'}" class="map-image" id="mapImage" draggable="false">
                <canvas class="overlay-canvas" id="tempCanvas"></canvas>
                <canvas class="overlay-canvas" id="cloudCanvas"></canvas>
                <canvas class="overlay-canvas" id="weatherCanvas"></canvas>
                <canvas class="overlay-canvas" id="windCanvas"></canvas>
                <canvas class="overlay-canvas" id="pressureCanvas"></canvas>
                <canvas class="overlay-canvas" id="frontCanvas"></canvas>
                <div class="markers-container" id="markersContainer"></div>
                <div class="coord-marker-container" id="coordMarkerContainer"></div>
            </div>
        </div>
    `;
    
    mapImage = document.getElementById('mapImage');
    markersContainer = document.getElementById('markersContainer');
    tempCanvas = document.getElementById('tempCanvas');
    weatherCanvas = document.getElementById('weatherCanvas');
    cloudCanvas = document.getElementById('cloudCanvas');
    windCanvas = document.getElementById('windCanvas');
    pressureCanvas = document.getElementById('pressureCanvas');
    frontCanvas = document.getElementById('frontCanvas');
    
    mapImage.onload = () => {
        mapConfig.dimensions = {
            width: mapImage.naturalWidth,
            height: mapImage.naturalHeight
        };
        
        [tempCanvas, weatherCanvas, cloudCanvas, windCanvas, pressureCanvas, frontCanvas].forEach(canvas => {
            canvas.width = mapConfig.dimensions.width;
            canvas.height = mapConfig.dimensions.height;
        });
        
        centerMap();
    };
    
    setupMapInteraction();
    setupLayerControls();
}

function initDaySelector() {
    const container = document.getElementById('daySelector');
    if (!container) return;

    const days = [];
    for (let i = 0; i < 8; i++) {
        const date = getDateForDayOffset(i);
        days.push({
            offset: i,
            date,
            label: formatDayLabel(date, i)
        });
    }

    container.innerHTML = days.map(d => `
        <button class="day-btn ${d.offset === currentDayOffset ? 'active' : ''}" 
                data-offset="${d.offset}" 
                title="${d.date}">
            ${d.label}
        </button>
    `).join('');
    
    container.querySelectorAll('.day-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentDayOffset = parseInt(btn.dataset.offset, 10);
            container.querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            updateDayBarDatetime();
            loadWeatherForDay(currentDayOffset);
        });
    });

    updateDayBarDatetime();
}

let regimeCache = {};

async function fetchRegimeForDate(date) {
    if (regimeCache[date]) return regimeCache[date];
    try {
        const response = await fetch(`${API_BASE}/weather/synoptic?date=${date}`);
        const data = await response.json();
        if (data.success) {
            regimeCache[date] = data;
            return data;
        }
    } catch (e) {
        console.warn('Failed to fetch synoptic regime:', e);
    }
    return null;
}

async function updateDayBarDatetime() {
    const el = document.getElementById('dayBarDatetime');
    if (!el) return;
    const date = getDateForDayOffset(currentDayOffset);
    const dateObj = new Date(date);
    const dateStr = dateObj.toLocaleDateString('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });
    const hour = String(animationHour).padStart(2, '0');
    
    // Show date + time immediately
    el.textContent = `${formatDayLabel(date, currentDayOffset)}, ${dateStr} | ${hour}:00`;
    
    // Fetch and append regime label
    const regime = await fetchRegimeForDate(date);
    if (regime && regime.label) {
        el.innerHTML = `${formatDayLabel(date, currentDayOffset)}, ${dateStr} | ${hour}:00 <span class="regime-badge">${regime.label}</span>`;
    }
}

function initAnimationControls() {
    const playBtn = document.getElementById('animatePlay');
    const pauseBtn = document.getElementById('animatePause');
    const slider = document.getElementById('hourSlider');
    const nowHour = new Date().getHours();
    animationHour = nowHour;
    if (slider) slider.value = nowHour;
    updateHourDisplay();
    
    if (playBtn) {
        playBtn.addEventListener('click', startAnimation);
    }
    
    if (pauseBtn) {
        pauseBtn.addEventListener('click', stopAnimation);
    }
    
    if (slider) {
        slider.addEventListener('input', (e) => {
            animationHour = parseInt(e.target.value);
            updateHourDisplay();
            renderOverlaysForHour(animationHour);
        });
    }
}

async function startAnimation() {
    if (isAnimating) return;
    await ensureDayWeatherLoaded(getDateForDayOffset(currentDayOffset));
    isAnimating = true;
    animationHour = new Date().getHours();
    
    const playBtn = document.getElementById('animatePlay');
    const pauseBtn = document.getElementById('animatePause');
    const slider = document.getElementById('hourSlider');
    if (slider) slider.value = animationHour;
    if (playBtn) playBtn.classList.add('hidden');
    if (pauseBtn) pauseBtn.classList.remove('hidden');

    updateHourDisplay();
    renderOverlaysForHour(animationHour);
    
    animationInterval = setInterval(() => {
        animationHour++;
        if (animationHour > 23) animationHour = 0;
        
        const slider = document.getElementById('hourSlider');
        if (slider) slider.value = animationHour;
        
        updateHourDisplay();
        renderOverlaysForHour(animationHour);
    }, 500);
}

function stopAnimation() {
    isAnimating = false;
    if (animationInterval) {
        clearInterval(animationInterval);
        animationInterval = null;
    }
    
    const playBtn = document.getElementById('animatePlay');
    const pauseBtn = document.getElementById('animatePause');
    if (playBtn) playBtn.classList.remove('hidden');
    if (pauseBtn) pauseBtn.classList.add('hidden');
}

function updateHourDisplay() {
    const display = document.getElementById('currentHourDisplay');
    if (display) {
        display.textContent = `${String(animationHour).padStart(2, '0')}:00`;
    }
    updateDayBarDatetime();
}

async function loadWeatherForDay(dayOffset) {
    const date = getDateForDayOffset(dayOffset);
    setStatus(`Lade Wetter für ${formatDayLabel(date, dayOffset)}...`);
    
    try {
        await ensureDayWeatherLoaded(date);
        
        if (dayOffset === 0) {
            renderStationMarkers();
            renderWeatherTable();
        } else {
            renderStationMarkersForDay(date);
            renderWeatherTableForDay(date);
        }
        
        renderOverlays();
        updatePrognosis();
        setStatus('Bereit');
        
    } catch (error) {
        console.error('Failed to load day weather:', error);
        setStatus('Fehler beim Laden');
    }
}

async function ensureDayWeatherLoaded(date) {
    const promises = stations.map(async (station) => {
        const cacheKey = `${station.id}-${date}`;
        if (!dayWeatherCache[cacheKey]) {
            try {
                const response = await fetch(`${API_BASE}/stations/${station.id}/day-hours?date=${date}`);
                const data = await response.json();
                if (data.success && data.data) {
                    dayWeatherCache[cacheKey] = data.data;
                }
            } catch (e) {
                console.warn(`Failed to load day weather for station ${station.id}`);
            }
        }
    });
    await Promise.all(promises);
}

function renderStationMarkersForDay(date) {
    if (!markersContainer) return;
    
    const hour = currentDayOffset === 0 ? new Date().getHours() : 12;
    
    markersContainer.innerHTML = stations.map(station => {
        const cacheKey = `${station.id}-${date}`;
        const hourData = dayWeatherCache[cacheKey]?.find(h => h.timestamp.includes(`${String(hour).padStart(2, '0')}:00:00`));
        
        const weatherState = hourData?.weather_state || station.weather_state || 'sunny';
        const temp = hourData?.temperature !== undefined ? Math.round(hourData.temperature) : (station.temperature !== null ? Math.round(station.temperature) : '—');
        
        const forecast = forecastCache[station.id];
        let tempHigh = temp;
        let tempLow = temp;
        
        if (forecast && forecast[currentDayOffset]) {
            tempHigh = Math.round(forecast[currentDayOffset].temp_high);
            tempLow = Math.round(forecast[currentDayOffset].temp_low);
        }
        
        const scaleFactor = Math.max(1, 1 / transform.scale);
        const markerScale = Math.min(2, Math.max(0.8, scaleFactor));
        
        const name = station.name.replace(' Station', '');
        
        return `
            <div class="weather-marker ${selectedStation?.id === station.id ? 'selected' : ''}" 
                 data-station-id="${station.id}" 
                 style="left: ${station.x_coord}px; top: ${station.y_coord}px; transform: scale(${markerScale});">
                <div class="marker-content">
                    <span class="marker-weather-icon">${renderWeatherIcon(weatherState, hour)}</span>
                    <span class="marker-name">${name}</span>
                    <span class="marker-temp-high">${tempHigh}°</span>
                    <span class="marker-temp-low">${tempLow}°</span>
                </div>
            </div>
        `;
    }).join('');
    
    markersContainer.querySelectorAll('.weather-marker').forEach(marker => {
        marker.addEventListener('click', (e) => {
            e.stopPropagation();
            const stationId = parseInt(marker.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            if (station) selectStation(station);
        });
    });
}

function setupMapInteraction() {
    const viewport = mapContainer.querySelector('.map-viewport');
    
    viewport.addEventListener('mousedown', (e) => {
        if (e.target.closest('.weather-marker')) return;
        isDragging = true;
        dragStart = { x: e.clientX - transform.x, y: e.clientY - transform.y };
        viewport.style.cursor = 'grabbing';
    });
    
    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        transform.x = e.clientX - dragStart.x;
        transform.y = e.clientY - dragStart.y;
        updateTransform();
    });
    
    document.addEventListener('mouseup', () => {
        isDragging = false;
        if (mapContainer) {
            const vp = mapContainer.querySelector('.map-viewport');
            if (vp) vp.style.cursor = 'grab';
        }
    });
    
    viewport.addEventListener('wheel', (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? 0.9 : 1.1;
        const newScale = Math.max(mapConfig.minZoom, Math.min(mapConfig.maxZoom, transform.scale * delta));
        
        const rect = viewport.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;
        
        const scaleChange = newScale / transform.scale;
        transform.x = mouseX - (mouseX - transform.x) * scaleChange;
        transform.y = mouseY - (mouseY - transform.y) * scaleChange;
        transform.scale = newScale;
        
        updateTransform();
        updateMarkerSizes();
    });
    
    viewport.addEventListener('click', (e) => {
        if (e.target.closest('.weather-marker')) return;
        
        const coordPanel = document.getElementById('coordPanel');
        if (coordPanel && !coordPanel.classList.contains('hidden')) {
            const rect = mapImage.getBoundingClientRect();
            const x = Math.round((e.clientX - rect.left) / transform.scale);
            const y = Math.round((e.clientY - rect.top) / transform.scale);
            
            if (x >= 0 && x < mapConfig.dimensions.width && y >= 0 && y < mapConfig.dimensions.height) {
                handleMapClick(x, y);
            }
        }
    });
    
    document.getElementById('zoomIn').addEventListener('click', () => zoom(1.2));
    document.getElementById('zoomOut').addEventListener('click', () => zoom(0.8));
    document.getElementById('resetView').addEventListener('click', centerMap);
}

function updateMarkerSizes() {
    if (!markersContainer) return;
    
    const scaleFactor = Math.max(1, 1 / transform.scale);
    const markerScale = Math.min(2, Math.max(0.8, scaleFactor));
    
    markersContainer.querySelectorAll('.weather-marker').forEach(marker => {
        marker.style.transform = `scale(${markerScale})`;
    });
}

function zoom(factor) {
    const newScale = Math.max(mapConfig.minZoom, Math.min(mapConfig.maxZoom, transform.scale * factor));
    const viewport = mapContainer.querySelector('.map-viewport');
    const rect = viewport.getBoundingClientRect();
    const centerX = rect.width / 2;
    const centerY = rect.height / 2;
    
    const scaleChange = newScale / transform.scale;
    transform.x = centerX - (centerX - transform.x) * scaleChange;
    transform.y = centerY - (centerY - transform.y) * scaleChange;
    transform.scale = newScale;
    
    updateTransform();
    updateMarkerSizes();
}

function centerMap() {
    const viewport = mapContainer.querySelector('.map-viewport');
    const rect = viewport.getBoundingClientRect();
    
    const scaleX = rect.width / mapConfig.dimensions.width;
    const scaleY = rect.height / mapConfig.dimensions.height;
    transform.scale = Math.min(scaleX, scaleY) * 0.9;
    
    transform.x = (rect.width - mapConfig.dimensions.width * transform.scale) / 2;
    transform.y = (rect.height - mapConfig.dimensions.height * transform.scale) / 2;
    
    updateTransform();
    updateMarkerSizes();
}

function updateTransform() {
    const mapContent = document.getElementById('mapContent');
    mapContent.style.transform = `translate(${transform.x}px, ${transform.y}px) scale(${transform.scale})`;
}

function handleMapClick(x, y) {
    document.getElementById('pixelX').value = x;
    document.getElementById('pixelY').value = y;
    
    const markerContainer = document.getElementById('coordMarkerContainer');
    markerContainer.innerHTML = `<div class="coord-marker" style="left: ${x}px; top: ${y}px;"></div>`;
}

async function loadStations() {
    setStatus('Lade Wetterstationen...');
    
    try {
        const response = await fetchAPI(`${API_BASE}/stations/with-weather`);
        stations = response.data || [];
        
        await loadAllForecasts();
        await ensureDayWeatherLoaded(getDateForDayOffset(0));
        
        renderStationMarkers();
        renderWeatherTable();
        renderOverlays();
        updatePrognosis();
        
        setStatus(`${stations.length} Stationen geladen`);
        
    } catch (error) {
        console.error('Failed to load stations:', error);
        setStatus('Fehler beim Laden der Stationen');
    }
}

async function loadWarnings() {
    try {
        const response = await fetchAPI(`${API_BASE}/warnings`);
        renderWarnings(response.data || []);
    } catch (error) {
        console.warn('Failed to load warnings:', error);
    }
}

function renderWarnings(warnings) {
    const container = document.getElementById('warningsPanel');
    const list = document.getElementById('warningsList');
    const heading = container?.querySelector('.warnings-section-head h3');
    const severeBanner = document.getElementById('severeBanner');
    if (!container || !list || !severeBanner) return;

    if (!warnings.length) {
        container.classList.add('hidden');
        severeBanner.className = 'severe-banner hidden';
        severeBanner.innerHTML = '';
        return;
    }

    // Check for severe warnings (level 3-4)
    const severeWarnings = warnings.filter(w => w.level >= 3);
    const nonSevereWarnings = warnings.filter(w => w.level < 3);
    const maxLevel = Math.max(...warnings.map(w => w.level));

    // Show severe banner at top (only severe)
    if (severeBanner && severeWarnings.length > 0) {
        const bannerLevel = maxLevel >= 4 ? 'extreme' : 'severe';
        const bannerIcon = maxLevel >= 4 ? '🔴' : '🔶';
        const bannerText = maxLevel >= 4
            ? `Unwetterwarnungen aktiv – ${severeWarnings.length} schwere Warnung${severeWarnings.length > 1 ? 'en' : ''}`
            : `Wetterwarnungen aktiv – ${severeWarnings.length} Warnung${severeWarnings.length > 1 ? 'en' : ''}`;
        severeBanner.className = `severe-banner severe-banner--${bannerLevel}`;
        severeBanner.innerHTML = `<span class="severe-banner-icon">${bannerIcon}</span><span>${bannerText}</span>`;
        severeBanner.onclick = () => {
            const accordion = document.getElementById('warningsPanel');
            if (accordion) accordion.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
    } else {
        severeBanner.className = 'severe-banner hidden';
        severeBanner.innerHTML = '';
    }

    // Render only non-severe warnings in lower section
    list.innerHTML = nonSevereWarnings.map(w => `
        <div class="warning-item warning-level-${w.level}" style="--warning-accent: ${w.color}">
            <div class="warning-icon-wrap">
                <div class="warning-icon">${w.icon}</div>
            </div>
            <div class="warning-content">
                <div class="warning-head">
                    <div class="warning-region">${w.region}</div>
                    <span class="warning-level-chip">Stufe ${w.level}</span>
                </div>
                <div class="warning-title">${w.title}</div>
                <div class="warning-desc">${w.description}</div>
                <div class="warning-meta">${w.affected_stations || 0} Stationen betroffen</div>
            </div>
        </div>
    `).join('');

    if (heading) {
        const count = nonSevereWarnings.length;
        heading.textContent = count > 0
            ? `Weitere Wetterwarnungen (${count})`
            : 'Weitere Wetterwarnungen';
    }

    container.classList.toggle('hidden', nonSevereWarnings.length === 0);
}

function setupLayerControls() {
    const controls = {
        layerTemp: 'temp',
        layerWeather: 'weather',
        layerClouds: 'clouds',
        layerWind: 'wind',
        layerPressure: 'pressure',
        layerFront: 'front'
    };
    
    Object.entries(controls).forEach(([id, layer]) => {
        const btn = document.getElementById(id);
        if (btn) {
            overlayLayers[layer] = btn.classList.contains('active');
            btn.addEventListener('click', () => {
                btn.classList.toggle('active');
                overlayLayers[layer] = btn.classList.contains('active');
                renderOverlays();
                
                if (layer === 'temp') {
                    const legend = document.getElementById('tempLegend');
                    if (legend) legend.style.display = overlayLayers[layer] ? 'block' : 'none';
                }
            });
        }
    });
}

function renderOverlays() {
    if (!stations.length || !mapConfig?.dimensions) return;
    
    const hour = isAnimating ? animationHour : (currentDayOffset === 0 ? new Date().getHours() : 12);
    renderOverlaysForHour(hour);
}

function renderOverlaysForHour(hour) {
    const date = getDateForDayOffset(currentDayOffset);
    const hourTag = `${String(hour).padStart(2, '0')}:00:00`;
    
    [tempCanvas, weatherCanvas, cloudCanvas, windCanvas, pressureCanvas, frontCanvas].forEach(canvas => {
        if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
    });
    
    const stationDataForHour = stations.map(station => {
        const cacheKey = `${station.id}-${date}`;
        const hourData = dayWeatherCache[cacheKey]?.find(h => (h.timestamp || '').includes(hourTag));
        
        return hourData ? { ...station, ...hourData } : station;
    });
    
    if (overlayLayers.temp && tempCanvas) {
        renderTemperatureOverlay(stationDataForHour);
    }
    if (overlayLayers.weather && weatherCanvas) {
        renderWeatherOverlay(stationDataForHour);
    }
    if (overlayLayers.clouds && cloudCanvas) {
        renderCloudOverlay(stationDataForHour);
    }
    if (overlayLayers.wind && windCanvas) {
        renderWindOverlay(stationDataForHour);
    }
    if (overlayLayers.pressure && pressureCanvas) {
        renderPressureOverlay(hour);
    }
    if (overlayLayers.front && frontCanvas) {
        renderFrontOverlay(hour);
    }
}

function renderTemperatureOverlay(stationData) {
    const ctx = tempCanvas.getContext('2d');
    const width = tempCanvas.width;
    const height = tempCanvas.height;
    
    const stationsWithTemp = stationData.filter(s => s.temperature !== null && s.x_coord && s.y_coord);
    if (stationsWithTemp.length === 0) return;
    
    const imageData = ctx.createImageData(width, height);
    const data = imageData.data;
    
    const gridSize = 6;
    
    for (let y = 0; y < height; y += gridSize) {
        for (let x = 0; x < width; x += gridSize) {
            const temp = interpolateTemperature(x, y, stationsWithTemp);
            const color = temperatureToColor(temp);
            
            for (let dy = 0; dy < gridSize && y + dy < height; dy++) {
                for (let dx = 0; dx < gridSize && x + dx < width; dx++) {
                    const idx = ((y + dy) * width + (x + dx)) * 4;
                    data[idx] = color.r;
                    data[idx + 1] = color.g;
                    data[idx + 2] = color.b;
                    data[idx + 3] = 120;
                }
            }
        }
    }
    
    ctx.putImageData(imageData, 0, 0);
    ctx.filter = 'blur(15px)';
    ctx.drawImage(tempCanvas, 0, 0);
    ctx.filter = 'none';
}

function interpolateTemperature(x, y, stations) {
    let totalWeight = 0;
    let weightedTemp = 0;
    const power = 2.5;
    
    for (const station of stations) {
        const dx = station.x_coord - x;
        const dy = station.y_coord - y;
        const distance = Math.sqrt(dx * dx + dy * dy);
        
        if (distance < 1) return station.temperature;
        
        const weight = 1 / Math.pow(distance, power);
        totalWeight += weight;
        weightedTemp += station.temperature * weight;
    }
    
    return totalWeight > 0 ? weightedTemp / totalWeight : 0;
}

function temperatureToColor(temp) {
    const normalized = Math.max(0, Math.min(1, (temp + 15) / 50));
    
    const stops = [
        { pos: 0.0, r: 20, g: 0, b: 120 },
        { pos: 0.2, r: 0, g: 80, b: 200 },
        { pos: 0.35, r: 0, g: 180, b: 220 },
        { pos: 0.45, r: 0, g: 200, b: 100 },
        { pos: 0.55, r: 180, g: 220, b: 0 },
        { pos: 0.65, r: 255, g: 200, b: 0 },
        { pos: 0.75, r: 255, g: 120, b: 0 },
        { pos: 0.85, r: 220, g: 50, b: 0 },
        { pos: 1.0, r: 150, g: 0, b: 40 }
    ];
    
    let lower = stops[0];
    let upper = stops[stops.length - 1];
    
    for (let i = 0; i < stops.length - 1; i++) {
        if (normalized >= stops[i].pos && normalized <= stops[i + 1].pos) {
            lower = stops[i];
            upper = stops[i + 1];
            break;
        }
    }
    
    const range = upper.pos - lower.pos;
    const factor = range > 0 ? (normalized - lower.pos) / range : 0;
    
    return {
        r: Math.round(lower.r + (upper.r - lower.r) * factor),
        g: Math.round(lower.g + (upper.g - lower.g) * factor),
        b: Math.round(lower.b + (upper.b - lower.b) * factor)
    };
}

function renderWeatherOverlay(stationData) {
    const ctx = weatherCanvas.getContext('2d');
    const width = weatherCanvas.width;
    const height = weatherCanvas.height;
    
    const stationsWithPrecip = stationData.filter(s => s.precipitation > 0 && s.x_coord && s.y_coord);
    
    stationsWithPrecip.forEach(station => {
        const intensity = Math.min(station.precipitation / 5, 1);
        const radius = 180 + intensity * 250;
        const isSnow = station.temperature < 1;
        
        const gradient = ctx.createRadialGradient(
            station.x_coord, station.y_coord, 0,
            station.x_coord, station.y_coord, radius
        );
        
        if (isSnow) {
            gradient.addColorStop(0, `rgba(220, 230, 255, ${0.6 * intensity})`);
            gradient.addColorStop(0.4, `rgba(200, 210, 250, ${0.35 * intensity})`);
            gradient.addColorStop(1, 'rgba(200, 210, 250, 0)');
        } else {
            gradient.addColorStop(0, `rgba(30, 100, 220, ${0.7 * intensity})`);
            gradient.addColorStop(0.4, `rgba(50, 120, 200, ${0.4 * intensity})`);
            gradient.addColorStop(1, 'rgba(50, 120, 200, 0)');
        }
        
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);
        
        drawPrecipitationParticles(ctx, station, isSnow, intensity);
    });
}

function drawPrecipitationParticles(ctx, station, isSnow, intensity) {
    const particleCount = Math.floor(50 * intensity);
    const radius = 180 + intensity * 180;
    
    const seed = station.id + station.x_coord;
    const seededRandom = (i) => {
        const x = Math.sin(seed + i) * 10000;
        return x - Math.floor(x);
    };
    
    ctx.save();
    
    for (let i = 0; i < particleCount; i++) {
        const angle = seededRandom(i * 2) * Math.PI * 2;
        const dist = seededRandom(i * 2 + 1) * radius;
        const x = station.x_coord + Math.cos(angle) * dist;
        const y = station.y_coord + Math.sin(angle) * dist;
        
        if (isSnow) {
            ctx.fillStyle = `rgba(255, 255, 255, ${0.7 + seededRandom(i) * 0.3})`;
            ctx.beginPath();
            ctx.arc(x, y, 3 + seededRandom(i + 100) * 4, 0, Math.PI * 2);
            ctx.fill();
        } else {
            ctx.strokeStyle = `rgba(80, 140, 220, ${0.5 + seededRandom(i) * 0.5})`;
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.lineTo(x + 3, y + 12 + seededRandom(i) * 8);
            ctx.stroke();
        }
    }
    
    ctx.restore();
}

function renderCloudOverlay(stationData) {
    const ctx = cloudCanvas.getContext('2d');
    
    const stationsWithClouds = stationData.filter(s => s.cloud_cover > 20 && s.x_coord && s.y_coord);
    if (!stationsWithClouds.length) return;

    // Map scale: ~3493px ≈ 150km → ~23 px/km
    const PX_PER_KM = 23;
    // Current hour fractional (e.g. 14.3 = 14:18)
    const minutePhase = isAnimating ? ((Date.now() / 500) % 60) / 60 : 0;
    const hourFrac = animationHour + minutePhase;
    
    stationsWithClouds.forEach(station => {
        const coverage = station.cloud_cover / 100;
        const radius = 280 + coverage * 400;
        const cloudCount = Math.floor(6 + coverage * 14);
        const windSpeed = Math.max(Number(station.wind_speed) || 5, 5); // min 5 km/h
        const windDir = Number(station.wind_direction) || 0;
        // Wind direction = where wind comes FROM, so add 180 for movement direction
        const windRad = (windDir + 180) * (Math.PI / 180);
        
        // Drift in pixels: speed(km/h) * hours * px/km
        const driftKm = windSpeed * hourFrac;
        const driftPx = driftKm * PX_PER_KM;
        const driftX = Math.cos(windRad) * driftPx;
        const driftY = Math.sin(windRad) * driftPx;
        
        const seed = station.id * 7 + Math.floor(station.cloud_cover / 10);
        const seededRandom = (i) => {
            const x = Math.sin(seed + i) * 10000;
            return x - Math.floor(x);
        };
        
        for (let i = 0; i < cloudCount; i++) {
            const offsetX = (seededRandom(i * 2) - 0.5) * radius;
            const offsetY = (seededRandom(i * 2 + 1) - 0.5) * radius * 0.6;
            const cloudRadius = 60 + seededRandom(i + 50) * 120;
            
            // Wrap clouds: modulo map dimensions so they cycle back
            let centerX = (station.x_coord + offsetX + driftX) % (cloudCanvas.width + cloudRadius * 2);
            let centerY = (station.y_coord + offsetY + driftY) % (cloudCanvas.height + cloudRadius * 2);
            if (centerX < -cloudRadius) centerX += cloudCanvas.width + cloudRadius * 2;
            if (centerY < -cloudRadius) centerY += cloudCanvas.height + cloudRadius * 2;
            
            const gradient = ctx.createRadialGradient(
                centerX, centerY, 0,
                centerX, centerY, cloudRadius
            );
            
            gradient.addColorStop(0, `rgba(200, 200, 210, ${0.5 * coverage})`);
            gradient.addColorStop(0.4, `rgba(180, 180, 195, ${0.35 * coverage})`);
            gradient.addColorStop(1, 'rgba(160, 160, 180, 0)');
            
            ctx.fillStyle = gradient;
            ctx.beginPath();
            ctx.ellipse(
                centerX,
                centerY,
                cloudRadius,
                cloudRadius * 0.6,
                windRad * 0.3, // tilt clouds slightly with wind
                0, Math.PI * 2
            );
            ctx.fill();
        }
    });
}

function renderWindOverlay(stationData) {
    const ctx = windCanvas.getContext('2d');
    const width = windCanvas.width;
    const height = windCanvas.height;
    
    const stationsWithWind = stationData.filter(s => s.wind_speed > 0 && s.x_coord && s.y_coord && s.wind_direction !== null);
    if (!stationsWithWind.length) return;
    
    // Draw a grid of interpolated wind arrows
    const gridSpacing = 180;
    
    for (let gy = gridSpacing; gy < height - gridSpacing / 2; gy += gridSpacing) {
        for (let gx = gridSpacing; gx < width - gridSpacing / 2; gx += gridSpacing) {
            // IDW interpolation of wind at this grid point
            let wSum = 0, spdSum = 0, sinSum = 0, cosSum = 0;
            for (const s of stationsWithWind) {
                const dx = gx - s.x_coord;
                const dy = gy - s.y_coord;
                const dist = Math.sqrt(dx * dx + dy * dy);
                const w = 1 / (1 + Math.pow(dist / 500, 2));
                wSum += w;
                spdSum += w * s.wind_speed;
                const rad = s.wind_direction * Math.PI / 180;
                sinSum += w * Math.sin(rad);
                cosSum += w * Math.cos(rad);
            }
            
            const speed = spdSum / wSum;
            const dir = Math.atan2(sinSum / wSum, cosSum / wSum); // radians, FROM direction
            const moveDir = dir + Math.PI; // movement direction
            
            if (speed < 2) continue;
            
            // Arrow length scales with speed
            const arrowLen = Math.min(20 + speed * 3, gridSpacing * 0.7);
            const headLen = 8 + speed * 0.5;
            const headAngle = 0.38;
            
            const startX = gx - Math.cos(moveDir) * arrowLen * 0.5;
            const startY = gy - Math.sin(moveDir) * arrowLen * 0.5;
            const endX = gx + Math.cos(moveDir) * arrowLen * 0.5;
            const endY = gy + Math.sin(moveDir) * arrowLen * 0.5;
            
            // Color by speed
            const intensity = Math.min(speed / 40, 1);
            const r = Math.round(40 + intensity * 200);
            const g = Math.round(120 - intensity * 80);
            const b = Math.round(220 - intensity * 120);
            const alpha = 0.6 + intensity * 0.35;
            
            ctx.save();
            ctx.strokeStyle = `rgba(${r}, ${g}, ${b}, ${alpha})`;
            ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${alpha})`;
            ctx.lineWidth = 2 + intensity * 2;
            ctx.lineCap = 'round';
            
            // Shaft
            ctx.beginPath();
            ctx.moveTo(startX, startY);
            ctx.lineTo(endX, endY);
            ctx.stroke();
            
            // Arrowhead (filled triangle)
            ctx.beginPath();
            ctx.moveTo(endX, endY);
            ctx.lineTo(
                endX - Math.cos(moveDir - headAngle) * headLen,
                endY - Math.sin(moveDir - headAngle) * headLen
            );
            ctx.lineTo(
                endX - Math.cos(moveDir + headAngle) * headLen,
                endY - Math.sin(moveDir + headAngle) * headLen
            );
            ctx.closePath();
            ctx.fill();
            
            // Wind barbs for strong winds (>25 km/h): add short ticks
            if (speed > 25) {
                const barbCount = Math.floor(speed / 20);
                for (let bi = 0; bi < barbCount && bi < 3; bi++) {
                    const t = 0.25 + bi * 0.15;
                    const bx = startX + (endX - startX) * t;
                    const by = startY + (endY - startY) * t;
                    const perpX = -Math.sin(moveDir) * 12;
                    const perpY = Math.cos(moveDir) * 12;
                    ctx.beginPath();
                    ctx.moveTo(bx, by);
                    ctx.lineTo(bx + perpX, by + perpY);
                    ctx.stroke();
                }
            }
            
            ctx.restore();
        }
    }
    
    // Speed labels at station positions
    stationsWithWind.forEach(station => {
        if (station.wind_speed < 5) return;
        ctx.save();
        ctx.font = 'bold 28px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.strokeStyle = 'rgba(30, 60, 120, 0.9)';
        ctx.lineWidth = 3;
        const label = `${Math.round(station.wind_speed)}`;
        ctx.strokeText(label, station.x_coord, station.y_coord + 55);
        ctx.fillText(label, station.x_coord, station.y_coord + 55);
        ctx.restore();
    });
}

// ═══════════════════════════════════════════════════
//  PRESSURE OVERLAY – Isobar contour lines + H/L
// ═══════════════════════════════════════════════════
async function renderPressureOverlay(hour) {
    const date = getDateForDayOffset(currentDayOffset);
    const regime = await fetchRegimeForDate(date);
    if (!regime) return;
    
    const ctx = pressureCanvas.getContext('2d');
    const width = pressureCanvas.width;
    const height = pressureCanvas.height;
    
    const anomaly = regime.pressure_anomaly;
    const cx = regime.pressure_center_x;
    const cy = regime.pressure_center_y;
    const sigma = regime.pressure_sigma;
    
    // Calculate pressure at a point (Gaussian field)
    function pressureAt(x, y) {
        const dx = x - cx;
        const dy = y - cy;
        const distSq = dx * dx + dy * dy;
        return 1013.25 + anomaly * Math.exp(-distSq / (2 * sigma * sigma));
    }
    
    // Build a pressure grid
    const gridStep = 10;
    const cols = Math.ceil(width / gridStep);
    const rows = Math.ceil(height / gridStep);
    const grid = [];
    let pMin = Infinity, pMax = -Infinity;
    
    for (let r = 0; r < rows; r++) {
        grid[r] = [];
        for (let c = 0; c < cols; c++) {
            const p = pressureAt(c * gridStep, r * gridStep);
            grid[r][c] = p;
            if (p < pMin) pMin = p;
            if (p > pMax) pMax = p;
        }
    }
    
    // Subtle colour wash using radial gradient (fast)
    const isHighP = anomaly > 0;
    const absAnom = Math.abs(anomaly);
    const tintAlpha = Math.min(absAnom / 20, 1) * 0.25;
    
    // Clamp draw position to reasonable range
    const gradCx = Math.max(-2000, Math.min(width + 2000, cx));
    const gradCy = Math.max(-2000, Math.min(height + 2000, cy));
    const gradR = sigma * 2.5;
    
    const grad = ctx.createRadialGradient(gradCx, gradCy, 0, gradCx, gradCy, gradR);
    if (isHighP) {
        grad.addColorStop(0, `rgba(200, 80, 50, ${tintAlpha})`);
        grad.addColorStop(0.6, `rgba(200, 80, 50, ${tintAlpha * 0.3})`);
        grad.addColorStop(1, 'rgba(200, 80, 50, 0)');
    } else {
        grad.addColorStop(0, `rgba(40, 80, 200, ${tintAlpha})`);
        grad.addColorStop(0.6, `rgba(40, 80, 200, ${tintAlpha * 0.3})`);
        grad.addColorStop(1, 'rgba(40, 80, 200, 0)');
    }
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, width, height);
    
    // Draw isobar lines at every 1 hPa
    const isobarStep = 1;
    const firstIsobar = Math.ceil(pMin / isobarStep) * isobarStep;
    const lastIsobar = Math.floor(pMax / isobarStep) * isobarStep;
    
    ctx.save();
    
    for (let level = firstIsobar; level <= lastIsobar; level += isobarStep) {
        const isMajor = level % 5 === 0;
        
        ctx.strokeStyle = isMajor
            ? 'rgba(20, 60, 140, 0.85)'
            : 'rgba(60, 100, 170, 0.4)';
        ctx.lineWidth = isMajor ? 4 : 1.5;
        ctx.setLineDash(isMajor ? [] : [8, 6]);
        
        // Marching squares: find contour segments
        const segments = [];
        for (let r = 0; r < rows - 1; r++) {
            for (let c = 0; c < cols - 1; c++) {
                const tl = grid[r][c];
                const tr = grid[r][c + 1];
                const bl = grid[r + 1][c];
                const br = grid[r + 1][c + 1];
                
                const edges = [];
                if ((tl - level) * (tr - level) < 0) {
                    const t = (level - tl) / (tr - tl);
                    edges.push({ x: (c + t) * gridStep, y: r * gridStep });
                }
                if ((tr - level) * (br - level) < 0) {
                    const t = (level - tr) / (br - tr);
                    edges.push({ x: (c + 1) * gridStep, y: (r + t) * gridStep });
                }
                if ((bl - level) * (br - level) < 0) {
                    const t = (level - bl) / (br - bl);
                    edges.push({ x: (c + t) * gridStep, y: (r + 1) * gridStep });
                }
                if ((tl - level) * (bl - level) < 0) {
                    const t = (level - tl) / (bl - tl);
                    edges.push({ x: c * gridStep, y: (r + t) * gridStep });
                }
                
                if (edges.length >= 2) {
                    segments.push([edges[0], edges[1]]);
                    if (edges.length === 4) {
                        segments.push([edges[2], edges[3]]);
                    }
                }
            }
        }
        
        // Draw segments
        ctx.beginPath();
        for (const [a, b] of segments) {
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
        }
        ctx.stroke();
        
        // Label major isobars (every 5 hPa) – place multiple labels
        if (isMajor && segments.length > 10) {
            const labelPositions = [
                Math.floor(segments.length * 0.2),
                Math.floor(segments.length * 0.5),
                Math.floor(segments.length * 0.8)
            ];
            
            for (const pos of labelPositions) {
                const seg = segments[pos];
                if (!seg) continue;
                const lx = (seg[0].x + seg[1].x) / 2;
                const ly = (seg[0].y + seg[1].y) / 2;
                
                // Only label if on screen
                if (lx < 50 || lx > width - 50 || ly < 30 || ly > height - 30) continue;
                
                ctx.save();
                ctx.font = 'bold 30px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                // White background box
                const txt = `${level}`;
                const m = ctx.measureText(txt);
                ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
                ctx.fillRect(lx - m.width / 2 - 6, ly - 16, m.width + 12, 32);
                ctx.fillStyle = 'rgba(20, 50, 130, 1)';
                ctx.setLineDash([]);
                ctx.fillText(txt, lx, ly);
                ctx.restore();
            }
        }
    }
    
    // Draw H / T pressure centre label
    const isHigh = anomaly > 0;
    
    // Clamp centre to visible area for label
    const drawCx = Math.max(-200, Math.min(width + 200, cx));
    const drawCy = Math.max(-200, Math.min(height + 200, cy));
    
    if (drawCx > -200 && drawCx < width + 200 && drawCy > -200 && drawCy < height + 200) {
        const letter = isHigh ? 'H' : 'T';
        const color = isHigh ? '#c0392b' : '#2563eb';
        
        ctx.save();
        ctx.font = 'bold 120px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = color;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.lineWidth = 6;
        ctx.setLineDash([]);
        ctx.strokeText(letter, drawCx, drawCy);
        ctx.fillText(letter, drawCx, drawCy);
        
        // Pressure value below
        const centerP = pressureAt(drawCx, drawCy);
        ctx.font = 'bold 36px sans-serif';
        ctx.fillStyle = color;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
        ctx.lineWidth = 3;
        const pLabel = `${Math.round(centerP)} hPa`;
        ctx.strokeText(pLabel, drawCx, drawCy + 70);
        ctx.fillText(pLabel, drawCx, drawCy + 70);
        ctx.restore();
    }
    
    ctx.restore();
}

// ═══════════════════════════════════════════════════
//  FRONT OVERLAY – Cold/warm front lines with symbols
// ═══════════════════════════════════════════════════
async function renderFrontOverlay(hour) {
    const date = getDateForDayOffset(currentDayOffset);
    const regime = await fetchRegimeForDate(date);
    if (!regime || !regime.front_active) return;
    
    const ctx = frontCanvas.getContext('2d');
    const width = frontCanvas.width;
    const height = frontCanvas.height;
    
    const fsx = regime.front_start_x;
    const fsy = regime.front_start_y;
    const fvx = regime.front_speed_x;
    const fvy = regime.front_speed_y;
    
    // Front position at this hour
    const fx = fsx + fvx * hour;
    const fy = fsy + fvy * hour;
    
    // Movement direction
    const speed = Math.sqrt(fvx * fvx + fvy * fvy);
    if (speed < 0.01) return;
    const nx = fvx / speed; // normal (movement direction)
    const ny = fvy / speed;
    
    // Front line is perpendicular to movement direction
    const perpX = -ny;
    const perpY = nx;
    
    // Check if front is near the visible map (within ~2x map dimensions)
    // Signed distance from map center to front line
    const mapCx = width / 2, mapCy = height / 2;
    const signedDist = (fx - mapCx) * nx + (fy - mapCy) * ny;
    if (Math.abs(signedDist) > Math.max(width, height) * 2) return; // too far away
    
    // Draw front line extending across the map
    const lineHalfLen = Math.max(width, height) * 1.5;
    const x1 = fx - perpX * lineHalfLen;
    const y1 = fy - perpY * lineHalfLen;
    const x2 = fx + perpX * lineHalfLen;
    const y2 = fy + perpY * lineHalfLen;
    
    const isCold = regime.front_type === 'cold';
    
    ctx.save();
    
    // Front line color
    ctx.strokeStyle = isCold ? '#2563eb' : '#dc2626';
    ctx.lineWidth = 5;
    ctx.lineCap = 'round';
    
    // Main front line
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
    
    // Draw front symbols along the line
    const symbolSpacing = 80;
    const symbolSize = 16;
    const numSymbols = Math.floor(lineHalfLen * 2 / symbolSpacing);
    
    for (let i = -numSymbols / 2; i < numSymbols / 2; i++) {
        const t = i / (numSymbols / 2);
        const sx = fx + perpX * t * lineHalfLen;
        const sy = fy + perpY * t * lineHalfLen;
        
        // Skip if way off screen
        if (sx < -200 || sx > width + 200 || sy < -200 || sy > height + 200) continue;
        
        if (isCold) {
            // Cold front: triangles pointing in movement direction
            ctx.fillStyle = '#2563eb';
            ctx.beginPath();
            ctx.moveTo(sx + nx * symbolSize, sy + ny * symbolSize);
            ctx.lineTo(sx - perpX * symbolSize * 0.6, sy - perpY * symbolSize * 0.6);
            ctx.lineTo(sx + perpX * symbolSize * 0.6, sy + perpY * symbolSize * 0.6);
            ctx.closePath();
            ctx.fill();
        } else {
            // Warm front: semicircles pointing in movement direction
            ctx.fillStyle = '#dc2626';
            const angle = Math.atan2(ny, nx);
            ctx.beginPath();
            ctx.arc(sx, sy, symbolSize * 0.6, angle - Math.PI / 2, angle + Math.PI / 2);
            ctx.fill();
        }
    }
    
    // Label
    ctx.font = 'bold 32px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = isCold ? '#2563eb' : '#dc2626';
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
    ctx.lineWidth = 3;
    const label = isCold ? 'KALTFRONT' : 'WARMFRONT';
    // Place label at front position
    const labelX = Math.max(100, Math.min(width - 100, fx + nx * 60));
    const labelY = Math.max(60, Math.min(height - 60, fy + ny * 60));
    ctx.strokeText(label, labelX, labelY);
    ctx.fillText(label, labelX, labelY);
    
    // Precipitation zone shading (ahead of front)
    const zoneWidth = isCold ? 80 : 200; // cold = narrow, warm = wide
    const gradient = ctx.createLinearGradient(
        fx - nx * 20, fy - ny * 20,
        fx + nx * zoneWidth, fy + ny * zoneWidth
    );
    const zoneColor = isCold ? '50, 100, 200' : '200, 60, 60';
    gradient.addColorStop(0, `rgba(${zoneColor}, 0.2)`);
    gradient.addColorStop(0.5, `rgba(${zoneColor}, 0.08)`);
    gradient.addColorStop(1, `rgba(${zoneColor}, 0)`);
    
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.moveTo(fx - perpX * lineHalfLen, fy - perpY * lineHalfLen);
    ctx.lineTo(fx + perpX * lineHalfLen, fy + perpY * lineHalfLen);
    ctx.lineTo(fx + perpX * lineHalfLen + nx * zoneWidth, fy + perpY * lineHalfLen + ny * zoneWidth);
    ctx.lineTo(fx - perpX * lineHalfLen + nx * zoneWidth, fy - perpY * lineHalfLen + ny * zoneWidth);
    ctx.closePath();
    ctx.fill();
    
    ctx.restore();
}

async function loadAllForecasts() {
    const promises = stations.map(async (station) => {
        try {
            const response = await fetch(`${API_BASE}/forecast/${station.id}`);
            const data = await response.json();
            if (data.success && data.data) {
                forecastCache[station.id] = data.data;
            }
        } catch (error) {
            console.warn(`Failed to load forecast for station ${station.id}:`, error);
        }
    });
    
    await Promise.all(promises);
}

function renderStationMarkers() {
    if (!markersContainer) return;
    
    const currentHour = new Date().getHours();
    const scaleFactor = Math.max(1, 1 / transform.scale);
    const markerScale = Math.min(2, Math.max(0.8, scaleFactor));
    
    markersContainer.innerHTML = stations.map(station => {
        const weatherIcon = renderWeatherIcon(station.weather_state || 'sunny', currentHour);
        const temp = station.temperature !== null ? Math.round(station.temperature) : '—';
        
        const forecast = forecastCache[station.id];
        let tempHigh = temp;
        let tempLow = temp;
        
        if (forecast && forecast.length > 0) {
            const todayForecast = forecast[0];
            if (todayForecast) {
                tempHigh = Math.round(todayForecast.temp_high);
                tempLow = Math.round(todayForecast.temp_low);
            }
        }
        
        const name = station.name.replace(' Station', '');
        
        return `
            <div class="weather-marker ${selectedStation?.id === station.id ? 'selected' : ''}" 
                 data-station-id="${station.id}" 
                 style="left: ${station.x_coord}px; top: ${station.y_coord}px; transform: scale(${markerScale});">
                <div class="marker-content">
                    <span class="marker-weather-icon">${weatherIcon}</span>
                    <span class="marker-name">${name}</span>
                    <span class="marker-temp-high">${tempHigh}°</span>
                    <span class="marker-temp-low">${tempLow}°</span>
                </div>
            </div>
        `;
    }).join('');
    
    markersContainer.querySelectorAll('.weather-marker').forEach(marker => {
        marker.addEventListener('click', (e) => {
            e.stopPropagation();
            const stationId = parseInt(marker.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            if (station) selectStation(station);
        });
    });
}

function renderWeatherTable() {
    const tbody = document.getElementById('weatherTableBody');
    if (!tbody) return;
    
    const currentHour = new Date().getHours();
    
    tbody.innerHTML = stations.map(station => {
        const weatherIcon = renderWeatherIcon(station.weather_state || 'sunny', currentHour);
        const weatherState = translateWeatherState(station.weather_state);
        
        return `
            <tr data-station-id="${station.id}">
                <td class="table-station-name">${station.name}</td>
                <td>${station.region_name || '—'}</td>
                <td>${station.elevation || '—'} m</td>
                <td><span class="table-weather-icon">${weatherIcon}</span> ${weatherState}</td>
                <td class="table-temp">${station.temperature !== null ? station.temperature + '°C' : '—'}</td>
                <td>${station.temperature_feels_like !== null ? station.temperature_feels_like + '°C' : '—'}</td>
                <td>${station.humidity !== null ? station.humidity + '%' : '—'}</td>
                <td>${station.wind_speed !== null ? station.wind_speed + ' km/h' : '—'}</td>
                <td>${station.precipitation !== null ? station.precipitation + ' mm' : '—'}</td>
            </tr>
        `;
    }).join('');
    
    tbody.querySelectorAll('tr').forEach(row => {
        row.addEventListener('click', () => {
            const stationId = parseInt(row.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            if (station) {
                document.querySelector('[data-view="map"]').click();
                setTimeout(() => selectStation(station), 100);
            }
        });
    });
}

function renderWeatherTableForDay(date) {
    const tbody = document.getElementById('weatherTableBody');
    if (!tbody) return;
    
    const hour = 12;
    
    tbody.innerHTML = stations.map(station => {
        const cacheKey = `${station.id}-${date}`;
        const hourData = dayWeatherCache[cacheKey]?.find(h => h.timestamp.includes(`${String(hour).padStart(2, '0')}:00:00`));
        
        const weatherState = hourData?.weather_state || station.weather_state || 'sunny';
        const temp = hourData?.temperature !== undefined ? parseFloat(hourData.temperature).toFixed(1) : '—';
        const feelsLike = hourData?.temperature_feels_like !== undefined ? parseFloat(hourData.temperature_feels_like).toFixed(1) : '—';
        const humidity = hourData?.humidity !== undefined ? Math.round(hourData.humidity) : '—';
        const windSpeed = hourData?.wind_speed !== undefined ? parseFloat(hourData.wind_speed).toFixed(1) : '—';
        const precip = hourData?.precipitation !== undefined ? parseFloat(hourData.precipitation).toFixed(1) : '—';
        
        const weatherIcon = renderWeatherIcon(weatherState, hour);
        const weatherName = translateWeatherState(weatherState);
        
        return `
            <tr data-station-id="${station.id}">
                <td class="table-station-name">${station.name}</td>
                <td>${station.region_name || '—'}</td>
                <td>${station.elevation || '—'} m</td>
                <td><span class="table-weather-icon">${weatherIcon}</span> ${weatherName}</td>
                <td class="table-temp">${temp !== '—' ? temp + '°C' : '—'}</td>
                <td>${feelsLike !== '—' ? feelsLike + '°C' : '—'}</td>
                <td>${humidity !== '—' ? humidity + '%' : '—'}</td>
                <td>${windSpeed !== '—' ? windSpeed + ' km/h' : '—'}</td>
                <td>${precip !== '—' ? precip + ' mm' : '—'}</td>
            </tr>
        `;
    }).join('');
    
    tbody.querySelectorAll('tr').forEach(row => {
        row.addEventListener('click', () => {
            const stationId = parseInt(row.dataset.stationId);
            const station = stations.find(s => s.id === stationId);
            if (station) {
                document.querySelector('[data-view="map"]').click();
                setTimeout(() => selectStation(station), 100);
            }
        });
    });
}

function updatePrognosis() {
    const container = document.getElementById('prognosisContent');
    if (!container) return;

    if (!stations.length) {
        container.innerHTML = '<p class="prognosis-empty">Keine Daten verfügbar.</p>';
        return;
    }

    const pick = (items) => items[Math.floor(Math.random() * items.length)];

    const summarizeDay = (dayOffset, mountainOnly = false) => {
        const date = getDateForDayOffset(dayOffset);
        const dateObj = new Date(date);
        const dayName = dateObj.toLocaleDateString('de-DE', { weekday: 'long' });
        const dateLabel = dayOffset === 0 ? 'Heute' : (dayOffset === 1 ? 'Morgen' : dayName);

        let dayStations = stations.map(station => {
            const dayForecast = forecastCache[station.id]?.[dayOffset];
            return {
                elevation: station.elevation || 450,
                weatherState: dayForecast?.weather_state || station.weather_state || 'sunny',
                tempHigh: dayForecast ? Math.round(dayForecast.temp_high) : Math.round(station.temperature || 0),
                tempLow: dayForecast ? Math.round(dayForecast.temp_low) : Math.round((station.temperature || 0) - 5),
                precipProb: dayForecast?.precipitation_probability || 0,
                precipAmount: dayForecast?.precipitation_amount || 0,
            };
        });

        if (mountainOnly) dayStations = dayStations.filter(s => s.elevation > 800);
        if (!dayStations.length) return '';

        const highs = dayStations.map(s => s.tempHigh);
        const lows = dayStations.map(s => s.tempLow);
        const weatherCounts = {};
        dayStations.forEach(s => {
            weatherCounts[s.weatherState] = (weatherCounts[s.weatherState] || 0) + 1;
        });
        const dominant = Object.entries(weatherCounts).sort((a, b) => b[1] - a[1])[0][0];
        const dominantName = translateWeatherState(dominant).toLowerCase();
        const rainyCount = dayStations.filter(s => s.precipProb > 40).length;
        const maxPrecip = Math.max(...dayStations.map(s => s.precipAmount));
        const minTemp = Math.min(...lows);
        const maxTemp = Math.max(...highs);
        const avgHigh = Math.round(highs.reduce((a, b) => a + b, 0) / highs.length);
        const avgLow = Math.round(lows.reduce((a, b) => a + b, 0) / lows.length);

        const leadText = mountainOnly
            ? pick([
                `In den Höhenlagen zeigt sich ${dateLabel.toLowerCase()} überwiegend ${dominantName}.`,
                `${dateLabel} setzt sich in den Bergen meist ${dominantName}es Wetter durch.`,
                `Für die Bergregionen ist ${dateLabel.toLowerCase()} vor allem ${dominantName} zu erwarten.`
            ])
            : pick([
                `${dateLabel} bringt im Fürstentum überwiegend ${dominantName}es Wetter.`,
                `Im Tagesverlauf wird es ${dateLabel.toLowerCase()} meist ${dominantName}.`,
                `${dateLabel} dominiert insgesamt ${dominantName}es Wetter in Eulenthal.`
            ]);

        const tempText = mountainOnly
            ? pick([
                `Die Bergtemperaturen reichen von ${minTemp}° bis ${maxTemp}°, mit Mittelwerten um ${avgLow}° bis ${avgHigh}°.`,
                `Thermisch liegen die Hochlagen zwischen ${minTemp}° und ${maxTemp}°; typisch sind ${avgLow}° bis ${avgHigh}°.`,
                `In den Höhen bewegen sich die Temperaturen zwischen ${minTemp}° und ${maxTemp}°.`
            ])
            : pick([
                `Die Temperaturen liegen zwischen ${minTemp}° und ${maxTemp}°; häufig werden ${avgLow}° bis ${avgHigh}° erreicht.`,
                `Thermisch ist eine Spannweite von ${minTemp}° bis ${maxTemp}° zu erwarten.`,
                `Bei den Temperaturen sind ${minTemp}° bis ${maxTemp}° zu erwarten.`
            ]);

        let precipText;
        if (rainyCount > 0) {
            const amountText = maxPrecip > 0 ? `, lokal bis etwa ${maxPrecip.toFixed(1)} mm` : '';
            precipText = pick([
                `Niederschläge sind regional möglich${amountText}.`,
                `Zeitweise fällt stellenweise Niederschlag${amountText}.`,
                `Im Verlauf treten örtlich Niederschläge auf${amountText}.`
            ]);
        } else {
            precipText = pick([
                `Größtenteils bleibt es trocken.`,
                `Nennenswerter Niederschlag ist eher nicht zu erwarten.`,
                `Voraussichtlich verläuft der Tag überwiegend trocken.`
            ]);
        }

        let mountainExtra = '';
        if (mountainOnly) {
            mountainExtra = avgLow <= 0
                ? pick([
                    `In exponierten Lagen sind winterliche Abschnitte möglich.`,
                    `Vor allem in höheren Bereichen kann es zeitweise winterlich werden.`,
                    `In den Gipfellagen bleibt es stellenweise winterlich geprägt.`
                ])
                : pick([
                    `In windoffenen Lagen wirkt es spürbar frischer.`,
                    `Auf den Höhen ist die gefühlte Temperatur oft niedriger als im Tal.`,
                    `Auf Kammlagen bleibt die Luft deutlich kühler als in den Niederungen.`
                ]);
        }

        let text = `<strong>${dateLabel}, ${dayName}</strong>`;
        text += `${leadText} ${tempText} ${precipText} ${mountainExtra}`;

        return `<p class="prognosis-text">${text}</p>`;
    };

    const dayOffsets = [0, 1, 2];
    const weatherBlocks = dayOffsets.map(offset => summarizeDay(offset, false)).join('');
    const mountainBlocks = dayOffsets.map(offset => summarizeDay(offset, true)).filter(Boolean).join('');

    container.innerHTML = `
        <div class="prognosis-grid">
            <div class="prognosis-column">
                <h3 class="prognosis-title">Wetterprognose Eulenthal (3 Tage)</h3>
                ${weatherBlocks}
            </div>
            <div class="prognosis-column">
                <h3 class="prognosis-title">Bergprognose Eulenthal (3 Tage)</h3>
                ${mountainBlocks || '<p class="prognosis-empty">Keine Bergstationen verfügbar.</p>'}
            </div>
        </div>
    `;
}

function selectStation(station) {
    selectedStation = station;
    
    markersContainer.querySelectorAll('.weather-marker').forEach(marker => {
        marker.classList.toggle('selected', parseInt(marker.dataset.stationId) === station.id);
    });
    
    renderStationDetail(station);
    panToStation(station);
    
    const overlay = document.getElementById('stationOverlay');
    overlay.classList.add('visible');
}

function panToStation(station) {
    const viewport = mapContainer.querySelector('.map-viewport');
    const rect = viewport.getBoundingClientRect();
    
    const offsetX = 250;
    const targetX = rect.width / 2 + offsetX - station.x_coord * transform.scale;
    const targetY = rect.height / 2 - station.y_coord * transform.scale;
    
    transform.x = targetX;
    transform.y = targetY;
    
    updateTransform();
}

async function renderStationDetail(station) {
    const detailEl = document.getElementById('stationDetail');
    if (!station) return;
    
    const currentHour = new Date().getHours();
    const weatherIcon = renderWeatherIcon(station.weather_state || 'sunny', currentHour);
    const weatherState = translateWeatherState(station.weather_state);
    
    const now = new Date();
    const dateStr = now.toLocaleDateString('de-DE', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    const forecast = forecastCache[station.id] || [];
    const days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    
    const forecastHTML = forecast.slice(0, 8).map((f, idx) => {
        const date = new Date(f.forecast_date);
        const dayName = idx === 0 ? 'Heute' : (idx === 1 ? 'Morgen' : days[date.getDay()]);
        const icon = renderWeatherIcon(f.weather_state || 'sunny', 12);
        const precipProb = f.precipitation_probability || 0;
        
        return `
            <div class="forecast-day">
                <div class="forecast-day-name">${dayName}</div>
                <div class="forecast-day-icon">${icon}</div>
                <div class="forecast-day-temps">
                    <span class="forecast-temp-low">${Math.round(f.temp_low)}°</span>
                    <span class="forecast-temp-high">${Math.round(f.temp_high)}°</span>
                </div>
                <div class="forecast-day-precip">${precipProb > 0 ? `☔ ${precipProb}%` : ''}</div>
            </div>
        `;
    }).join('');
    
    const windDir = station.wind_direction || 0;
    const windArrow = getWindArrow(windDir);
    
    detailEl.innerHTML = `
        <div class="station-header">
            <div class="station-title">
                <h2 class="station-name">${station.name}</h2>
                <span class="station-meta">${dateStr}</span>
            </div>
            <div class="station-meta">${station.elevation || '—'}m Seehöhe</div>
            
            <div class="station-current">
                <div class="current-icon">${weatherIcon}</div>
                <div class="current-temp">${station.temperature !== null ? station.temperature + '°C' : '—'}</div>
                <div class="current-info">
                    <span>☔ ${station.precipitation || 0} mm</span>
                    <span>${weatherState}</span>
                </div>
            </div>
        </div>
        
        <div class="weekly-forecast">
            ${forecastHTML || '<div class="no-data">Keine Vorhersage verfügbar</div>'}
        </div>
        
        <div class="detail-forecast">
            <div class="detail-forecast-title">Detailprognose</div>
            <div id="hourlyForecast">
                <div class="loading">Lade Stundenwerte...</div>
            </div>
        </div>
        
        <div class="weather-grid">
            <div class="weather-grid-item">
                <div class="weather-grid-label">Gefühlt</div>
                <div class="weather-grid-value">${station.temperature_feels_like ?? '—'}<span class="unit">°C</span></div>
            </div>
            <div class="weather-grid-item">
                <div class="weather-grid-label">Luftfeuchtigkeit</div>
                <div class="weather-grid-value">${station.humidity ?? '—'}<span class="unit">%</span></div>
            </div>
            <div class="weather-grid-item">
                <div class="weather-grid-label">Luftdruck</div>
                <div class="weather-grid-value">${station.pressure ?? '—'}<span class="unit">hPa</span></div>
            </div>
            <div class="weather-grid-item">
                <div class="weather-grid-label">Wind</div>
                <div class="weather-grid-value">${windArrow} ${station.wind_speed ?? '—'}<span class="unit">km/h</span></div>
            </div>
            <div class="weather-grid-item">
                <div class="weather-grid-label">Bewölkung</div>
                <div class="weather-grid-value">${station.cloud_cover ?? '—'}<span class="unit">%</span></div>
            </div>
            <div class="weather-grid-item">
                <div class="weather-grid-label">Sichtweite</div>
                <div class="weather-grid-value">${station.visibility ?? '—'}<span class="unit">km</span></div>
            </div>
        </div>
    `;
    
    loadHourlyForecast(station.id);
}

function getWindArrow(degrees) {
    const arrows = ['↓', '↙', '←', '↖', '↑', '↗', '→', '↘'];
    const index = Math.round(degrees / 45) % 8;
    return arrows[index];
}

async function loadHourlyForecast(stationId) {
    const container = document.getElementById('hourlyForecast');
    if (!container) return;
    
    try {
        const today = getLocalDateISO();
        const tomorrow = getLocalDateISO(new Date(Date.now() + 86400000));
        
        const [todayRes, tomorrowRes] = await Promise.all([
            fetch(`${API_BASE}/stations/${stationId}/day-hours?date=${today}`),
            fetch(`${API_BASE}/stations/${stationId}/day-hours?date=${tomorrow}`)
        ]);
        
        const todayData = await todayRes.json();
        const tomorrowData = await tomorrowRes.json();
        
        const hours = [
            ...(todayData.data || []),
            ...(tomorrowData.data || [])
        ].sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp));
        
        const now = new Date();
        const currentHourFloor = new Date(now);
        currentHourFloor.setMinutes(0, 0, 0);
        
        const filteredHours = hours.filter(h => {
            const hourDate = new Date(h.timestamp);
            return hourDate >= currentHourFloor;
        }).slice(0, 24);
        
        if (filteredHours.length === 0) {
            container.innerHTML = '<div class="no-data">Keine Stundenwerte verfügbar</div>';
            return;
        }
        
        const iconsHTML = filteredHours.map(h => {
            const hour = new Date(h.timestamp).getHours();
            const icon = renderWeatherIcon(h.weather_state || 'sunny', hour);
            return `<div class="hourly-item"><div class="hourly-icon">${icon}</div></div>`;
        }).join('');
        
        const windHTML = filteredHours.map(h => {
            const arrow = getWindArrow(h.wind_direction || 0);
            const speed = Math.round(h.wind_speed || 0);
            return `<div class="wind-item"><span class="wind-arrow">${arrow}</span><span class="wind-speed">${speed}</span></div>`;
        }).join('');
        
        const temps = filteredHours.map(h => h.temperature);
        const precips = filteredHours.map(h => h.precipitation || 0);
        const labels = filteredHours.map(h => {
            const d = new Date(h.timestamp);
            return `${String(d.getHours()).padStart(2, '0')}:00`;
        });
        
        const timeLabelsHTML = filteredHours.map(h => {
            const d = new Date(h.timestamp);
            return `<div class="hourly-time">${String(d.getHours()).padStart(2, '0')}</div>`;
        }).join('');
        
        container.innerHTML = `
            <div class="hourly-scroll-container">
                <div class="hourly-grid">
                    <div class="hourly-row hourly-times">${timeLabelsHTML}</div>
                    <div class="hourly-row hourly-icons">${iconsHTML}</div>
                    <div class="hourly-row hourly-winds">${windHTML}</div>
                </div>
            </div>
            <div class="temp-chart-container">
                <canvas id="hourlyChart"></canvas>
            </div>
        `;
        
        const ctx = document.getElementById('hourlyChart');
        if (ctx && typeof Chart !== 'undefined') {
            const minTemp = Math.floor(Math.min(...temps) - 1);
            const maxTemp = Math.ceil(Math.max(...temps) + 1);
            const maxPrecip = Math.max(...precips, 5);
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Temperatur °C',
                            data: temps,
                            borderColor: '#dc2626',
                            backgroundColor: (context) => {
                                const ctx = context.chart.ctx;
                                const gradient = ctx.createLinearGradient(0, 0, 0, 140);
                                gradient.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
                                gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
                                return gradient;
                            },
                            borderWidth: 2.5,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            fill: true,
                            tension: 0.35,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Niederschlag mm',
                            data: precips,
                            type: 'bar',
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderRadius: 2,
                            barPercentage: 0.6,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { display: true, grid: { display: false } },
                        y: { type: 'linear', position: 'left', min: minTemp, max: maxTemp },
                        y1: { type: 'linear', position: 'right', min: 0, max: maxPrecip, grid: { drawOnChartArea: false } }
                    }
                }
            });
        }
        
    } catch (error) {
        console.error('Failed to load hourly forecast:', error);
        container.innerHTML = '<div class="no-data">Fehler beim Laden der Stundenwerte</div>';
    }
}

function setupViewToggle() {
    const viewTabs = document.querySelectorAll('.view-tab');
    const mapView = document.getElementById('mapView');
    const tableView = document.getElementById('tableView');
    const animationControls = document.getElementById('animationControls');
    
    viewTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            viewTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            if (tab.dataset.view === 'map') {
                mapView.classList.remove('hidden');
                tableView.classList.add('hidden');
                if (animationControls) animationControls.classList.remove('hidden');
            } else {
                mapView.classList.add('hidden');
                tableView.classList.remove('hidden');
                if (animationControls) animationControls.classList.add('hidden');
            }
        });
    });
}

function updateDateTime() {
    const container = document.getElementById('currentDateTime');
    if (!container) return;
    
    const now = new Date();
    const dateStr = now.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    
    container.innerHTML = `${dateStr} | ${timeStr}`;
}

function setupEventListeners() {
    const closeBtn = document.getElementById('overlayClose');
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeOverlay();
    });
    
    setupAutoRefresh();
}

function closeOverlay() {
    const overlay = document.getElementById('stationOverlay');
    if (overlay) overlay.classList.remove('visible');
    selectedStation = null;
    document.querySelectorAll('.weather-marker').forEach(m => m.classList.remove('selected'));
}

function setupAutoRefresh() {
    setInterval(async () => {
        if (!document.hidden) {
            await loadStations();
            await loadWarnings();
            updateLastUpdate();
        }
    }, 5 * 60 * 1000);
    
    document.addEventListener('visibilitychange', async () => {
        if (!document.hidden) {
            await loadStations();
            await loadWarnings();
            updateLastUpdate();
        }
    });
}

async function fetchAPI(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { 'Content-Type': 'application/json', ...options.headers }
    });
    
    if (!response.ok) throw new Error(`API error: ${response.status}`);
    
    const data = await response.json();
    if (!data.success && data.error) throw new Error(data.error);
    
    return data.data !== undefined ? { data: data.data, ...data } : data;
}

function setStatus(text) {
    const el = document.getElementById('statusText');
    if (el) el.textContent = text;
}

function updateLastUpdate() {
    const el = document.getElementById('lastUpdate');
    if (el) el.textContent = `Letzte Aktualisierung: ${new Date().toLocaleTimeString('de-DE')}`;
}
