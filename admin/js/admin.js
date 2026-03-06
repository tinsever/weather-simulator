/**
 * Eulenmeteo Admin Panel
 * Main JavaScript for admin functionality
 */

// ============================================
// Global State
// ============================================

let currentPage = 'dashboard';
let regions = [];
let stations = [];
let features = [];
let options = {};
let mapConfig = null;

// ============================================
// Initialization
// ============================================

document.addEventListener('DOMContentLoaded', init);

async function init() {
    const authCheck = await checkAuth();
    if (!authCheck) {
        window.location.href = '/admin/login';
        return;
    }
    
    await loadOptions();
    await loadMapConfig();
    
    setupNavigation();
    setupModal();
    
    document.getElementById('logoutBtn').addEventListener('click', logout);
    
    const hash = window.location.hash.slice(1) || 'dashboard';
    navigateTo(hash);
}

async function checkAuth() {
    try {
        const response = await fetch('/admin/auth/check', { credentials: 'include' });
        const data = await response.json();
        return data.authenticated;
    } catch {
        return false;
    }
}

async function logout() {
    try {
        await fetch('/admin/logout', { 
            method: 'POST',
            credentials: 'include'
        });
        window.location.href = '/admin/login';
    } catch (error) {
        showNotification('error', 'Logout Failed', error.message);
    }
}

async function loadOptions() {
    try {
        const response = await fetchAPI('/api/admin/options');
        options = response.data;
    } catch (error) {
        console.error('Failed to load options:', error);
    }
}

async function loadMapConfig() {
    try {
        const response = await fetch('/api/map/config');
        mapConfig = await response.json();
    } catch (error) {
        console.error('Failed to load map config:', error);
    }
}

// ============================================
// Navigation
// ============================================

function setupNavigation() {
    document.querySelectorAll('.nav-link[data-page]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const page = link.dataset.page;
            navigateTo(page);
        });
    });
    
    window.addEventListener('hashchange', () => {
        const hash = window.location.hash.slice(1) || 'dashboard';
        navigateTo(hash);
    });
}

function navigateTo(page) {
    currentPage = page;
    window.location.hash = page;
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.toggle('active', link.dataset.page === page);
    });
    
    switch (page) {
        case 'dashboard':
            loadDashboard();
            break;
        case 'regions':
            loadRegions();
            break;
        case 'stations':
            loadStations();
            break;
        case 'features':
            loadFeatures();
            break;
        default:
            loadDashboard();
    }
}

// ============================================
// Dashboard
// ============================================

async function loadDashboard() {
    setPageTitle('Dashboard', '');
    
    const content = document.getElementById('mainContent');
    content.innerHTML = '<div class="loading-spinner"></div>';
    
    try {
        const response = await fetchAPI('/api/admin/stats');
        const stats = response.data;
        const canImportLegacyDb = stats.stations === 0;
        const importPanel = canImportLegacyDb ? `
            <div class="data-table-container import-db-panel mt-2">
                <div class="table-header">
                    <h2>Import Existing Database</h2>
                </div>
                <div class="import-db-content">
                    <p class="text-dim">No weather stations were found. You can import a legacy installation database to migrate regions, stations, weather data, and all related tables.</p>
                    <form id="importDbForm" class="import-db-form">
                        <input type="file" id="legacyDbFile" name="database_file" accept=".db,.sqlite,.sqlite3" required>
                        <button type="submit" class="btn btn-primary" id="importDbBtn">
                            <span>⬆</span> Import .db File
                        </button>
                    </form>
                    <p class="text-muted text-small">Import is disabled automatically once weather stations already exist.</p>
                </div>
            </div>
        ` : '';
        
        content.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">🗺️</span>
                    <div class="stat-info">
                        <h3>${stats.regions}</h3>
                        <p>Regions</p>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">📡</span>
                    <div class="stat-info">
                        <h3>${stats.stations}</h3>
                        <p>Weather Stations</p>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">🏔️</span>
                    <div class="stat-info">
                        <h3>${stats.features}</h3>
                        <p>Topographic Features</p>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">🌡️</span>
                    <div class="stat-info">
                        <h3>${stats.weatherRecords}</h3>
                        <p>Weather Records</p>
                    </div>
                </div>
            </div>
            
            <div class="data-table-container">
                <div class="table-header">
                    <h2>Quick Actions</h2>
                </div>
                <div style="padding: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="navigateTo('regions')">
                        <span>➕</span> Add Region
                    </button>
                    <button class="btn btn-primary" onclick="navigateTo('stations')">
                        <span>➕</span> Add Station
                    </button>
                    <button class="btn btn-primary" onclick="navigateTo('features')">
                        <span>➕</span> Add Feature
                    </button>
                    <button class="btn btn-secondary" onclick="generateWeather()">
                        <span>⚡</span> Generate Weather
                    </button>
                </div>
            </div>
            ${importPanel}
        `;

        if (canImportLegacyDb) {
            const importForm = document.getElementById('importDbForm');
            if (importForm) {
                importForm.addEventListener('submit', importLegacyDatabase);
            }
        }
    } catch (error) {
        content.innerHTML = `<div class="error-message">${error.message}</div>`;
    }
}

async function importLegacyDatabase(event) {
    event.preventDefault();

    const fileInput = document.getElementById('legacyDbFile');
    const submitButton = document.getElementById('importDbBtn');
    const file = fileInput?.files?.[0];

    if (!file) {
        showNotification('warning', 'No File Selected', 'Please choose a .db file to import.');
        return;
    }

    if (!confirm('This will replace the current database. Continue?')) {
        return;
    }

    const formData = new FormData();
    formData.append('database_file', file);

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Importing...';
    }

    try {
        const response = await fetch('/api/admin/import-db', {
            method: 'POST',
            credentials: 'include',
            body: formData,
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || `API error: ${response.status}`);
        }

        const importedStations = data?.data?.weatherStations ?? 0;
        const importedTables = data?.data?.tables ?? 0;
        showNotification('success', 'Import Successful', `Imported ${importedStations} station(s) across ${importedTables} table(s).`);
        await loadDashboard();
    } catch (error) {
        showNotification('error', 'Import Failed', error.message || 'Could not import database.');
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = '<span>⬆</span> Import .db File';
        }
    }
}

async function generateWeather() {
    try {
        showNotification('info', 'Generating...', 'Creating weather data for all stations');
        const response = await fetchAPI('/api/weather/generate', { method: 'POST' });
        showNotification('success', 'Success', `Generated weather for ${response.generated} stations`);
    } catch (error) {
        showNotification('error', 'Error', error.message);
    }
}

// ============================================
// Regions Management
// ============================================

async function loadRegions() {
    setPageTitle('Regions', `
        <button class="btn btn-primary" onclick="openRegionModal()">
            <span>➕</span> Add Region
        </button>
    `);
    
    const content = document.getElementById('mainContent');
    content.innerHTML = '<div class="loading-spinner"></div>';
    
    try {
        const response = await fetchAPI('/api/admin/regions');
        regions = response.data;
        
        if (regions.length === 0) {
            content.innerHTML = `
                <div class="data-table-container">
                    <div class="table-empty">
                        <p>No regions found. Create your first region!</p>
                        <button class="btn btn-primary mt-2" onclick="openRegionModal()">Add Region</button>
                    </div>
                </div>
            `;
            return;
        }
        
        content.innerHTML = `
            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Elevation</th>
                            <th>Land Usage</th>
                            <th>Topography</th>
                            <th>Bounds</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${regions.map(r => `
                            <tr>
                                <td>
                                    <strong>${r.name}</strong>
                                    ${r.description ? `<br><span class="text-dim text-small">${r.description}</span>` : ''}
                                </td>
                                <td>${r.elevation}m</td>
                                <td><span class="badge badge-primary">${r.land_usage}</span></td>
                                <td><span class="badge badge-primary">${r.topography}</span></td>
                                <td class="text-mono text-dim">${r.min_x},${r.min_y} - ${r.max_x},${r.max_y}</td>
                                <td class="row-actions">
                                    <button class="btn btn-secondary btn-small" onclick="openRegionModal(${r.id})">Edit</button>
                                    <button class="btn btn-danger btn-small" onclick="deleteRegion(${r.id})">Delete</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } catch (error) {
        content.innerHTML = `<div class="error-message">${error.message}</div>`;
    }
}

function openRegionModal(id = null) {
    const region = id ? regions.find(r => r.id === id) : null;
    const isEdit = !!region;
    
    openModal(isEdit ? 'Edit Region' : 'Add Region', `
        <form id="regionForm">
            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="${region?.name || ''}" required>
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description">${region?.description || ''}</textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="min_x">Min X *</label>
                    <input type="number" id="min_x" name="min_x" value="${region?.min_x || 0}" required>
                </div>
                <div class="form-group">
                    <label for="min_y">Min Y *</label>
                    <input type="number" id="min_y" name="min_y" value="${region?.min_y || 0}" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="max_x">Max X *</label>
                    <input type="number" id="max_x" name="max_x" value="${region?.max_x || 100}" required>
                </div>
                <div class="form-group">
                    <label for="max_y">Max Y *</label>
                    <input type="number" id="max_y" name="max_y" value="${region?.max_y || 100}" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="center_x">Center X *</label>
                    <input type="number" id="center_x" name="center_x" value="${region?.center_x || 50}" required>
                </div>
                <div class="form-group">
                    <label for="center_y">Center Y *</label>
                    <input type="number" id="center_y" name="center_y" value="${region?.center_y || 50}" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="elevation">Elevation (m)</label>
                <input type="number" id="elevation" name="elevation" value="${region?.elevation || 450}">
            </div>
            
            <div class="form-row-3">
                <div class="form-group">
                    <label for="land_usage">Land Usage</label>
                    <select id="land_usage" name="land_usage">
                        ${(options.land_usage || []).map(o => `
                            <option value="${o}" ${region?.land_usage === o ? 'selected' : ''}>${o}</option>
                        `).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label for="hydrology">Hydrology</label>
                    <select id="hydrology" name="hydrology">
                        ${(options.hydrology || []).map(o => `
                            <option value="${o}" ${region?.hydrology === o ? 'selected' : ''}>${o}</option>
                        `).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label for="topography">Topography</label>
                    <select id="topography" name="topography">
                        ${(options.topography || []).map(o => `
                            <option value="${o}" ${region?.topography === o ? 'selected' : ''}>${o}</option>
                        `).join('')}
                    </select>
                </div>
            </div>
            
            <div class="form-row-3">
                <div class="form-group">
                    <label for="temperature_modifier">Temp Modifier (°C)</label>
                    <input type="number" id="temperature_modifier" name="temperature_modifier" step="0.1" value="${region?.temperature_modifier || 0}">
                </div>
                <div class="form-group">
                    <label for="precipitation_modifier">Precip Modifier</label>
                    <input type="number" id="precipitation_modifier" name="precipitation_modifier" step="0.1" value="${region?.precipitation_modifier || 1}">
                </div>
                <div class="form-group">
                    <label for="wind_exposure">Wind Exposure</label>
                    <input type="number" id="wind_exposure" name="wind_exposure" step="0.1" value="${region?.wind_exposure || 1}">
                </div>
            </div>
        </form>
    `, async () => {
        const form = document.getElementById('regionForm');
        const data = Object.fromEntries(new FormData(form));
        
        ['min_x', 'min_y', 'max_x', 'max_y', 'center_x', 'center_y', 'elevation'].forEach(f => {
            data[f] = parseInt(data[f]);
        });
        ['temperature_modifier', 'precipitation_modifier', 'wind_exposure'].forEach(f => {
            data[f] = parseFloat(data[f]);
        });
        
        try {
            if (isEdit) {
                await fetchAPI(`/api/admin/regions/${id}`, { method: 'PUT', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Region updated successfully');
            } else {
                await fetchAPI('/api/admin/regions', { method: 'POST', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Region created successfully');
            }
            closeModal();
            loadRegions();
        } catch (error) {
            showNotification('error', 'Error', error.message);
        }
    });
}

async function deleteRegion(id) {
    if (!confirm('Are you sure you want to delete this region? This cannot be undone.')) return;
    
    try {
        await fetchAPI(`/api/admin/regions/${id}`, { method: 'DELETE' });
        showNotification('success', 'Success', 'Region deleted successfully');
        loadRegions();
    } catch (error) {
        showNotification('error', 'Error', error.message);
    }
}

// ============================================
// Stations Management
// ============================================

async function loadStations() {
    setPageTitle('Weather Stations', `
        <button class="btn btn-primary" onclick="openStationModal()">
            <span>➕</span> Add Station
        </button>
    `);
    
    const content = document.getElementById('mainContent');
    content.innerHTML = '<div class="loading-spinner"></div>';
    
    try {
        const regionsResponse = await fetchAPI('/api/admin/regions');
        regions = regionsResponse.data;
        
        const response = await fetchAPI('/api/admin/stations');
        stations = response.data;
        
        if (stations.length === 0) {
            content.innerHTML = `
                <div class="data-table-container">
                    <div class="table-empty">
                        <p>No weather stations found. Create your first station!</p>
                        <button class="btn btn-primary mt-2" onclick="openStationModal()">Add Station</button>
                    </div>
                </div>
            `;
            return;
        }
        
        content.innerHTML = `
            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Region</th>
                            <th>Coordinates</th>
                            <th>Elevation</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${stations.map(s => `
                            <tr>
                                <td><strong>${s.name}</strong></td>
                                <td>${s.region_name || '—'}</td>
                                <td class="text-mono text-dim">${s.x_coord}, ${s.y_coord}</td>
                                <td>${s.elevation}m</td>
                                <td><span class="badge badge-primary">${s.station_type}</span></td>
                                <td class="row-actions">
                                    <button class="btn btn-secondary btn-small" onclick="openStationModal(${s.id})">Edit</button>
                                    <button class="btn btn-danger btn-small" onclick="deleteStation(${s.id})">Delete</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } catch (error) {
        content.innerHTML = `<div class="error-message">${error.message}</div>`;
    }
}

function openStationModal(id = null) {
    const station = id ? stations.find(s => s.id === id) : null;
    const isEdit = !!station;
    
    openModal(isEdit ? 'Edit Station' : 'Add Station', `
        <form id="stationForm">
            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="${station?.name || ''}" required>
            </div>
            
            <div class="form-group">
                <label for="region_id">Region *</label>
                <select id="region_id" name="region_id" required>
                    <option value="">Select a region</option>
                    ${regions.map(r => `
                        <option value="${r.id}" ${station?.region_id == r.id ? 'selected' : ''}>${r.name}</option>
                    `).join('')}
                </select>
            </div>
            
            <div class="coord-picker-container">
                <div class="coord-picker-map" id="coordPickerMap" onclick="handleCoordClick(event)">
                    <img src="${mapConfig?.mapImage || '/maps/map.png'}" alt="Map">
                    <div class="coord-picker-marker" id="coordMarker" style="display: ${station ? 'block' : 'none'}; left: ${station?.x_coord || 0}px; top: ${station?.y_coord || 0}px;"></div>
                </div>
                <div class="coord-picker-hint">Click on the map to set coordinates</div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="x_coord">X Coordinate *</label>
                    <input type="number" id="x_coord" name="x_coord" value="${station?.x_coord || ''}" required>
                </div>
                <div class="form-group">
                    <label for="y_coord">Y Coordinate *</label>
                    <input type="number" id="y_coord" name="y_coord" value="${station?.y_coord || ''}" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="elevation">Elevation (m)</label>
                    <input type="number" id="elevation" name="elevation" value="${station?.elevation || 450}">
                </div>
                <div class="form-group">
                    <label for="station_type">Station Type</label>
                    <select id="station_type" name="station_type">
                        ${(options.station_type || []).map(o => `
                            <option value="${o}" ${station?.station_type === o ? 'selected' : ''}>${o}</option>
                        `).join('')}
                    </select>
                </div>
            </div>
        </form>
    `, async () => {
        const form = document.getElementById('stationForm');
        const data = Object.fromEntries(new FormData(form));
        
        data.region_id = parseInt(data.region_id);
        data.x_coord = parseInt(data.x_coord);
        data.y_coord = parseInt(data.y_coord);
        data.elevation = parseInt(data.elevation);
        
        try {
            if (isEdit) {
                await fetchAPI(`/api/admin/stations/${id}`, { method: 'PUT', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Station updated successfully');
            } else {
                await fetchAPI('/api/admin/stations', { method: 'POST', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Station created successfully');
            }
            closeModal();
            loadStations();
        } catch (error) {
            showNotification('error', 'Error', error.message);
        }
    });
}

function handleCoordClick(event) {
    const rect = event.target.getBoundingClientRect();
    const x = Math.round(event.clientX - rect.left);
    const y = Math.round(event.clientY - rect.top);
    
    document.getElementById('x_coord').value = x;
    document.getElementById('y_coord').value = y;
    
    const marker = document.getElementById('coordMarker');
    marker.style.display = 'block';
    marker.style.left = x + 'px';
    marker.style.top = y + 'px';
}

async function deleteStation(id) {
    if (!confirm('Are you sure you want to delete this station? All associated weather data will also be deleted.')) return;
    
    try {
        await fetchAPI(`/api/admin/stations/${id}`, { method: 'DELETE' });
        showNotification('success', 'Success', 'Station deleted successfully');
        loadStations();
    } catch (error) {
        showNotification('error', 'Error', error.message);
    }
}

// ============================================
// Features Management
// ============================================

async function loadFeatures() {
    setPageTitle('Topographic Features', `
        <button class="btn btn-primary" onclick="openFeatureModal()">
            <span>➕</span> Add Feature
        </button>
    `);
    
    const content = document.getElementById('mainContent');
    content.innerHTML = '<div class="loading-spinner"></div>';
    
    try {
        const regionsResponse = await fetchAPI('/api/admin/regions');
        regions = regionsResponse.data;
        
        const response = await fetchAPI('/api/admin/features');
        features = response.data;
        
        if (features.length === 0) {
            content.innerHTML = `
                <div class="data-table-container">
                    <div class="table-empty">
                        <p>No topographic features found. Create your first feature!</p>
                        <button class="btn btn-primary mt-2" onclick="openFeatureModal()">Add Feature</button>
                    </div>
                </div>
            `;
            return;
        }
        
        content.innerHTML = `
            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Region</th>
                            <th>Coordinates</th>
                            <th>Elevation</th>
                            <th>Radius</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${features.map(f => `
                            <tr>
                                <td>
                                    <strong>${f.name}</strong>
                                    ${f.description ? `<br><span class="text-dim text-small">${f.description}</span>` : ''}
                                </td>
                                <td><span class="badge badge-primary">${f.feature_type}</span></td>
                                <td>${f.region_name}</td>
                                <td class="text-mono text-dim">${f.x_coord}, ${f.y_coord}</td>
                                <td>${f.elevation || '—'}m</td>
                                <td>${f.influence_radius}px</td>
                                <td class="row-actions">
                                    <button class="btn btn-secondary btn-small" onclick="openFeatureModal(${f.id})">Edit</button>
                                    <button class="btn btn-danger btn-small" onclick="deleteFeature(${f.id})">Delete</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } catch (error) {
        content.innerHTML = `<div class="error-message">${error.message}</div>`;
    }
}

function openFeatureModal(id = null) {
    const feature = id ? features.find(f => f.id === id) : null;
    const isEdit = !!feature;
    
    openModal(isEdit ? 'Edit Feature' : 'Add Feature', `
        <form id="featureForm">
            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" value="${feature?.name || ''}" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="region_id">Region *</label>
                    <select id="region_id" name="region_id" required>
                        <option value="">Select a region</option>
                        ${regions.map(r => `
                            <option value="${r.id}" ${feature?.region_id == r.id ? 'selected' : ''}>${r.name}</option>
                        `).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label for="feature_type">Feature Type *</label>
                    <select id="feature_type" name="feature_type" required>
                        ${(options.feature_type || []).map(o => `
                            <option value="${o}" ${feature?.feature_type === o ? 'selected' : ''}>${o}</option>
                        `).join('')}
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description">${feature?.description || ''}</textarea>
            </div>
            
            <div class="coord-picker-container">
                <div class="coord-picker-map" id="coordPickerMap" onclick="handleCoordClick(event)">
                    <img src="${mapConfig?.mapImage || '/maps/map.png'}" alt="Map">
                    <div class="coord-picker-marker" id="coordMarker" style="display: ${feature ? 'block' : 'none'}; left: ${feature?.x_coord || 0}px; top: ${feature?.y_coord || 0}px;"></div>
                </div>
                <div class="coord-picker-hint">Click on the map to set coordinates</div>
            </div>
            
            <div class="form-row-3">
                <div class="form-group">
                    <label for="x_coord">X Coordinate *</label>
                    <input type="number" id="x_coord" name="x_coord" value="${feature?.x_coord || ''}" required>
                </div>
                <div class="form-group">
                    <label for="y_coord">Y Coordinate *</label>
                    <input type="number" id="y_coord" name="y_coord" value="${feature?.y_coord || ''}" required>
                </div>
                <div class="form-group">
                    <label for="elevation">Elevation (m)</label>
                    <input type="number" id="elevation" name="elevation" value="${feature?.elevation || ''}">
                </div>
            </div>
            
            <div class="form-group">
                <label for="influence_radius">Influence Radius (px)</label>
                <input type="number" id="influence_radius" name="influence_radius" value="${feature?.influence_radius || 50}">
            </div>
            
            <div class="form-row-3">
                <div class="form-group">
                    <label for="temperature_effect">Temp Effect (°C)</label>
                    <input type="number" id="temperature_effect" name="temperature_effect" step="0.1" value="${feature?.temperature_effect || 0}">
                </div>
                <div class="form-group">
                    <label for="precipitation_effect">Precip Effect</label>
                    <input type="number" id="precipitation_effect" name="precipitation_effect" step="0.1" value="${feature?.precipitation_effect || 1}">
                </div>
                <div class="form-group">
                    <label for="wind_effect">Wind Effect</label>
                    <input type="number" id="wind_effect" name="wind_effect" step="0.1" value="${feature?.wind_effect || 1}">
                </div>
            </div>
        </form>
    `, async () => {
        const form = document.getElementById('featureForm');
        const data = Object.fromEntries(new FormData(form));
        
        data.region_id = parseInt(data.region_id);
        data.x_coord = parseInt(data.x_coord);
        data.y_coord = parseInt(data.y_coord);
        data.elevation = data.elevation ? parseInt(data.elevation) : null;
        data.influence_radius = parseInt(data.influence_radius);
        ['temperature_effect', 'precipitation_effect', 'wind_effect'].forEach(f => {
            data[f] = parseFloat(data[f]);
        });
        
        try {
            if (isEdit) {
                await fetchAPI(`/api/admin/features/${id}`, { method: 'PUT', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Feature updated successfully');
            } else {
                await fetchAPI('/api/admin/features', { method: 'POST', body: JSON.stringify(data) });
                showNotification('success', 'Success', 'Feature created successfully');
            }
            closeModal();
            loadFeatures();
        } catch (error) {
            showNotification('error', 'Error', error.message);
        }
    });
}

async function deleteFeature(id) {
    if (!confirm('Are you sure you want to delete this feature? This cannot be undone.')) return;
    
    try {
        await fetchAPI(`/api/admin/features/${id}`, { method: 'DELETE' });
        showNotification('success', 'Success', 'Feature deleted successfully');
        loadFeatures();
    } catch (error) {
        showNotification('error', 'Error', error.message);
    }
}

// ============================================
// Modal
// ============================================

let modalSaveCallback = null;

function setupModal() {
    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalCancel').addEventListener('click', closeModal);
    document.getElementById('modalSave').addEventListener('click', () => {
        if (modalSaveCallback) modalSaveCallback();
    });
    document.getElementById('modalOverlay').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeModal();
    });
}

function openModal(title, content, onSave) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = content;
    modalSaveCallback = onSave;
    document.getElementById('modalOverlay').classList.add('active');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
    modalSaveCallback = null;
}

// ============================================
// Notifications
// ============================================

function showNotification(type, title, message) {
    const container = document.getElementById('notifications');
    const id = Date.now();
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.id = `notification-${id}`;
    notification.innerHTML = `
        <span class="notification-icon">${icons[type]}</span>
        <div class="notification-content">
            <div class="notification-title">${title}</div>
            <div class="notification-message">${message}</div>
        </div>
    `;
    
    container.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// ============================================
// Utilities
// ============================================

function setPageTitle(title, actions = '') {
    document.getElementById('pageTitle').textContent = title;
    document.getElementById('headerActions').innerHTML = actions;
}

async function fetchAPI(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...options.headers
        }
    });
    
    const data = await response.json();
    
    if (!response.ok || !data.success) {
        throw new Error(data.error || `API error: ${response.status}`);
    }
    
    return data;
}
