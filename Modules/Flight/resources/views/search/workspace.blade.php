<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Flight Search & Supplier Aggregation Engine</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0B0F19;
            --bg-card: rgba(22, 30, 46, 0.7);
            --bg-card-hover: rgba(30, 41, 62, 0.85);
            --border: rgba(255, 255, 255, 0.08);
            --border-highlight: rgba(99, 102, 241, 0.4);
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --primary: #6366F1;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent-cheapest: #10B981;
            --accent-fastest: #06B6D4;
            --accent-baggage: #A855F7;
            --accent-recommended: #F59E0B;
            --radius-lg: 16px;
            --radius-md: 10px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            background-image:
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(245, 158, 11, 0.1) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(16, 185, 129, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
            padding: 30px 20px;
        }

        h1, h2, h3, h4 {
            font-family: 'Outfit', sans-serif;
        }

        .container {
            max-width: 1320px;
            margin: 0 auto;
        }

        /* Top Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .brand-title {
            font-size: 26px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-live {
            background: rgba(16, 185, 129, 0.2);
            color: #34D399;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }

        /* Search Form Card */
        .search-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.5);
        }

        .trip-type-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .trip-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            padding: 6px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .trip-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 15px var(--primary-glow);
        }

        .search-grid {
            display: grid;
            grid-template-columns: 1.2fr 1.2fr 1.1fr 1fr 1fr 1.2fr auto;
            gap: 12px;
            align-items: end;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .input-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-control {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .input-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-glow);
        }

        .btn-search {
            background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
            border: none;
            color: #fff;
            padding: 12px 28px;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 4px 15px var(--primary-glow);
            transition: transform 0.15s ease;
            height: 44px;
        }

        .btn-search:hover {
            transform: translateY(-2px);
        }

        /* Best Fares Banner (Phase 3 6-Factor Model) */
        .best-fares-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 30px;
        }

        .highlight-card {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .highlight-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.2);
        }

        .card-recommended {
            border-color: rgba(245, 158, 11, 0.4);
            box-shadow: 0 10px 25px -5px rgba(245, 158, 11, 0.15);
        }

        .card-cheapest {
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15);
        }

        .card-fastest {
            border-color: rgba(6, 182, 212, 0.4);
            box-shadow: 0 10px 25px -5px rgba(6, 182, 212, 0.15);
        }

        .card-baggage {
            border-color: rgba(168, 85, 247, 0.4);
            box-shadow: 0 10px 25px -5px rgba(168, 85, 247, 0.15);
        }

        .highlight-tag {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .highlight-price {
            font-size: 24px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 4px;
        }

        .highlight-flight {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .highlight-meta {
            margin-top: 12px;
            font-size: 11px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            padding-top: 8px;
            display: flex;
            justify-content: space-between;
        }

        /* Results & Filters Layout */
        .content-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 25px;
        }

        /* Sidebar Filters */
        .filters-panel {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            align-self: start;
        }

        .filter-section {
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }

        .filter-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .filter-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .filter-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 13px;
            color: var(--text-primary);
            cursor: pointer;
        }

        .filter-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Results List */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .sort-select {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            color: #fff;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 13px;
            outline: none;
        }

        .itinerary-card {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .itinerary-card:hover {
            border-color: rgba(99, 102, 241, 0.35);
            background: var(--bg-card-hover);
        }

        .itinerary-main {
            padding: 22px;
            display: grid;
            grid-template-columns: 180px 1fr 180px;
            gap: 20px;
            align-items: center;
        }

        .airline-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .airline-name {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
        }

        .flight-no {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .flight-path {
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-align: center;
        }

        .time-box h3 {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }

        .time-box span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .duration-line {
            flex: 1;
            padding: 0 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .duration-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .route-bar {
            width: 100%;
            height: 2px;
            background: var(--border);
            position: relative;
            margin-bottom: 4px;
        }

        .route-stops {
            font-size: 11px;
            color: #10B981;
            font-weight: 600;
        }

        .price-booking-block {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .price-from {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .price-amount {
            font-size: 24px;
            font-weight: 800;
            color: #fff;
        }

        .btn-view-fares {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.35);
            color: #A5B4FC;
            padding: 6px 14px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-view-fares:hover {
            background: var(--primary);
            color: #fff;
        }

        /* Fare Options Expansion Drawer */
        .fares-drawer {
            background: rgba(11, 15, 25, 0.6);
            border-top: 1px solid var(--border);
            padding: 18px 22px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

        .fare-option-card {
            background: rgba(22, 30, 46, 0.9);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .fare-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 8px;
        }

        .badge-saver { background: rgba(59, 130, 246, 0.2); color: #60A5FA; }
        .badge-flex { background: rgba(245, 158, 11, 0.2); color: #FBBF24; }
        .badge-rec { background: rgba(16, 185, 129, 0.2); color: #34D399; }

        .fare-feature-list {
            list-style: none;
            margin: 10px 0;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .fare-feature-list li {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-select-fare {
            background: var(--primary);
            border: none;
            color: #fff;
            padding: 8px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            text-align: center;
        }

        /* Badges on main card */
        .itinerary-badges {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }

        .badge-pill-small {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .pill-cheapest { background: rgba(16, 185, 129, 0.2); color: #34D399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .pill-fastest { background: rgba(6, 182, 212, 0.2); color: #22D3EE; border: 1px solid rgba(6, 182, 212, 0.4); }
        .pill-baggage { background: rgba(168, 85, 247, 0.2); color: #C084FC; border: 1px solid rgba(168, 85, 247, 0.4); }
        .pill-recommended { background: rgba(245, 158, 11, 0.2); color: #FBBF24; border: 1px solid rgba(245, 158, 11, 0.4); }

        /* Loader */
        .loader {
            display: none;
            text-align: center;
            padding: 60px 0;
            color: var(--text-secondary);
            font-size: 16px;
        }
        .spinner {
            border: 3px solid rgba(255,255,255,0.1);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <!-- Top Header -->
        <header class="header">
            <div>
                <h1 class="brand-title">
                    <span>✈️ NexDine Global Flight Engine</span>
                </h1>
                <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                    Enterprise Internal Flight Inventory & Multi-Supplier Aggregation Network
                </p>
            </div>
            <div>
                <span class="badge-pill badge-live">● Phase 11 Aggregation Online</span>
            </div>
        </header>

        <!-- Search Form -->
        <section class="search-card">
            <div class="trip-type-bar">
                <button type="button" class="trip-btn active" id="btnOneWay" onclick="setTripType('one_way')">One Way</button>
                <button type="button" class="trip-btn" id="btnRoundTrip" onclick="setTripType('round_trip')">Round Trip</button>
            </div>

            <form id="searchForm" onsubmit="executeSearch(event)" class="search-grid">
                <div class="input-group">
                    <label for="originInput">From (Origin)</label>
                    <input type="text" id="originInput" class="input-control" value="{{ $defaultOrigin }}" maxlength="3" required placeholder="e.g. DEL">
                </div>

                <div class="input-group">
                    <label for="destInput">To (Destination)</label>
                    <input type="text" id="destInput" class="input-control" value="{{ $defaultDest }}" maxlength="3" required placeholder="e.g. BOM">
                </div>

                <div class="input-group">
                    <label for="departureDate">Departure Date</label>
                    <input type="date" id="departureDate" class="input-control" value="{{ $defaultDepDate }}" required>
                </div>

                <div class="input-group" id="returnDateGroup" style="display: none;">
                    <label for="returnDate">Return Date</label>
                    <input type="date" id="returnDate" class="input-control">
                </div>

                <div class="input-group">
                    <label for="cabinClass">Cabin Class</label>
                    <select id="cabinClass" class="input-control">
                        <option value="ECONOMY" selected>Economy</option>
                        <option value="PREMIUM_ECONOMY">Premium Economy</option>
                        <option value="BUSINESS">Business</option>
                        <option value="FIRST">First Class</option>
                    </select>
                </div>

                <div class="input-group">
                    <label for="passengersInput">Travelers</label>
                    <select id="passengersInput" class="input-control">
                        <option value="1" selected>1 Adult</option>
                        <option value="2">2 Adults</option>
                        <option value="3">3 Travelers</option>
                        <option value="4">4+ Travelers</option>
                    </select>
                </div>

                <button type="submit" id="btnSubmitSearch" class="btn-search">Search Flights</button>
            </form>
        </section>

        <!-- Loader -->
        <div id="loadingIndicator" class="loader">
            <div class="spinner"></div>
            Searching internal inventory & configured supplier APIs...
        </div>

        <!-- Best Fares Highlights Ribbon (Phase 3 6-Factor Model) -->
        <section id="bestFaresBanner" class="best-fares-grid" style="display: none;">
            <div class="highlight-card card-recommended">
                <div>
                    <div class="highlight-tag" style="color: var(--accent-recommended);">
                        ★ Recommended Fare (Best Value)
                    </div>
                    <div class="highlight-price" id="recPrice">₹0</div>
                    <div class="highlight-flight" id="recFlight">Flight Details</div>
                </div>
                <div class="highlight-meta">
                    <span id="recDuration">-</span>
                    <span>Customer-Centric 6-Factor</span>
                </div>
            </div>

            <div class="highlight-card card-cheapest">
                <div>
                    <div class="highlight-tag" style="color: var(--accent-cheapest);">
                        🏷️ Cheapest Fare
                    </div>
                    <div class="highlight-price" id="cheapPrice">₹0</div>
                    <div class="highlight-flight" id="cheapFlight">Flight Details</div>
                </div>
                <div class="highlight-meta">
                    <span id="cheapDuration">-</span>
                    <span>Lowest Price</span>
                </div>
            </div>

            <div class="highlight-card card-fastest">
                <div>
                    <div class="highlight-tag" style="color: var(--accent-fastest);">
                        ⚡ Fastest Flight
                    </div>
                    <div class="highlight-price" id="fastPrice">₹0</div>
                    <div class="highlight-flight" id="fastFlight">Flight Details</div>
                </div>
                <div class="highlight-meta">
                    <span id="fastDuration">-</span>
                    <span>Shortest Travel Time</span>
                </div>
            </div>

            <div class="highlight-card card-baggage">
                <div>
                    <div class="highlight-tag" style="color: var(--accent-baggage);">
                        🧳 Best Baggage Allowance
                    </div>
                    <div class="highlight-price" id="bagPrice">₹0</div>
                    <div class="highlight-flight" id="bagFlight">Flight Details</div>
                </div>
                <div class="highlight-meta">
                    <span id="bagKg">-</span>
                    <span>Maximum Baggage</span>
                </div>
            </div>
        </section>

        <!-- Main Content (Filters + Results) -->
        <main class="content-layout">
            <!-- Sidebar Filters -->
            <aside class="filters-panel" id="filtersSidebar">
                <div class="filter-section">
                    <div class="filter-title">Stops</div>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="checkbox" id="stopDirect" checked onchange="applyClientFilters()"> Direct Flight (0 Stops)
                        </div>
                    </label>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="checkbox" id="stop1" checked onchange="applyClientFilters()"> 1 Stop
                        </div>
                    </label>
                </div>

                <div class="filter-section">
                    <div class="filter-title">Departure Time Window</div>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="radio" name="timeWindow" value="" checked onchange="applyClientFilters()"> Any Time
                        </div>
                    </label>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="radio" name="timeWindow" value="morning" onchange="applyClientFilters()"> Morning (05:00 - 11:59)
                        </div>
                    </label>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="radio" name="timeWindow" value="afternoon" onchange="applyClientFilters()"> Afternoon (12:00 - 17:59)
                        </div>
                    </label>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="radio" name="timeWindow" value="evening" onchange="applyClientFilters()"> Evening (18:00 - 23:59)
                        </div>
                    </label>
                </div>

                <div class="filter-section">
                    <div class="filter-title">Fare Features</div>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="checkbox" id="refundableOnly" onchange="applyClientFilters()"> Refundable Only
                        </div>
                    </label>
                    <label class="filter-option">
                        <div class="filter-checkbox">
                            <input type="checkbox" id="baggageIncluded" onchange="applyClientFilters()"> Check-in Baggage Included
                        </div>
                    </label>
                </div>
            </aside>

            <!-- Results Section -->
            <section class="results-container">
                <div class="results-header">
                    <div>
                        <h2 style="font-size: 18px; font-weight: 700;" id="resultsCountHeader">Available Flight Itineraries</h2>
                    </div>
                    <div>
                        <select id="sortBySelect" class="sort-select" onchange="applyClientFilters()">
                            <option value="recommended" selected>Sort by: Recommended (Customer Value)</option>
                            <option value="price_asc">Price: Lowest First</option>
                            <option value="price_desc">Price: Highest First</option>
                            <option value="duration_asc">Duration: Fastest First</option>
                            <option value="departure_asc">Departure: Earliest First</option>
                        </select>
                    </div>
                </div>

                <div id="resultsList">
                    <!-- Populated dynamically via JS -->
                </div>
            </section>
        </main>
    </div>

    <script>
        let currentTripType = 'one_way';
        let latestSearchResponse = null;

        function setTripType(type) {
            currentTripType = type;
            document.getElementById('btnOneWay').classList.toggle('active', type === 'one_way');
            document.getElementById('btnRoundTrip').classList.toggle('active', type === 'round_trip');
            document.getElementById('returnDateGroup').style.display = type === 'round_trip' ? 'flex' : 'none';
        }

        async function executeSearch(e) {
            if (e) e.preventDefault();

            const origin = document.getElementById('originInput').value.trim().toUpperCase();
            const destination = document.getElementById('destInput').value.trim().toUpperCase();
            const departureDate = document.getElementById('departureDate').value;
            const returnDate = currentTripType === 'round_trip' ? document.getElementById('returnDate').value : null;
            const cabinClass = document.getElementById('cabinClass').value;
            const adults = parseInt(document.getElementById('passengersInput').value) || 1;
            const sortBy = document.getElementById('sortBySelect').value;

            document.getElementById('loadingIndicator').style.display = 'block';
            document.getElementById('resultsList').innerHTML = '';
            document.getElementById('bestFaresBanner').style.display = 'none';

            try {
                const response = await fetch('/api/v1/flight/search', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        origin,
                        destination,
                        departure_date: departureDate,
                        return_date: returnDate,
                        trip_type: currentTripType,
                        cabin_class: cabinClass,
                        adults,
                        sort_by: sortBy
                    })
                });

                const data = await response.json();
                latestSearchResponse = data.body || data;
                renderResults(latestSearchResponse);
            } catch (err) {
                console.error(err);
                document.getElementById('resultsList').innerHTML = '<div style="padding: 40px; text-align: center; color: #EF4444;">Failed to retrieve flight results. Please try again.</div>';
            } finally {
                document.getElementById('loadingIndicator').style.display = 'none';
            }
        }

        function renderResults(data) {
            if (!data || !data.itineraries || data.itineraries.length === 0) {
                document.getElementById('resultsList').innerHTML = '<div style="padding: 50px; text-align: center; color: var(--text-muted); background: var(--bg-card); border-radius: var(--radius-lg);">No flights found matching your search parameters.</div>';
                document.getElementById('resultsCountHeader').innerText = '0 Flights Found';
                return;
            }

            document.getElementById('resultsCountHeader').innerText = `${data.itineraries.length} Flights Available (${data.params.origin} → ${data.params.destination})`;

            // Render Best Fares Banner
            if (data.best_fares) {
                document.getElementById('bestFaresBanner').style.display = 'grid';

                const bf = data.best_fares;
                if (bf.recommended_fare) {
                    document.getElementById('recPrice').innerText = '₹' + Number(bf.recommended_fare.final_customer_price).toLocaleString();
                    document.getElementById('recFlight').innerText = bf.recommended_fare.airline.name + ' (' + bf.recommended_fare.flight_number + ')';
                    document.getElementById('recDuration').innerText = bf.recommended_fare.duration;
                }
                if (bf.cheapest_fare) {
                    document.getElementById('cheapPrice').innerText = '₹' + Number(bf.cheapest_fare.final_customer_price).toLocaleString();
                    document.getElementById('cheapFlight').innerText = bf.cheapest_fare.airline.name + ' (' + bf.cheapest_fare.flight_number + ')';
                    document.getElementById('cheapDuration').innerText = bf.cheapest_fare.duration;
                }
                if (bf.fastest_fare) {
                    document.getElementById('fastPrice').innerText = '₹' + Number(bf.fastest_fare.final_customer_price).toLocaleString();
                    document.getElementById('fastFlight').innerText = bf.fastest_fare.airline.name + ' (' + bf.fastest_fare.flight_number + ')';
                    document.getElementById('fastDuration').innerText = bf.fastest_fare.duration;
                }
                if (bf.best_baggage_fare) {
                    document.getElementById('bagPrice').innerText = '₹' + Number(bf.best_baggage_fare.final_customer_price).toLocaleString();
                    document.getElementById('bagFlight').innerText = bf.best_baggage_fare.airline.name + ' (' + bf.best_baggage_fare.flight_number + ')';
                    document.getElementById('bagKg').innerText = bf.best_baggage_fare.baggage_kg + ' kg Total Baggage';
                }
            }

            // Render Itineraries
            let html = '';
            data.itineraries.forEach((it, idx) => {
                const badgesHtml = `
                    ${it.badges.is_recommended ? '<span class="badge-pill-small pill-recommended">★ Best Recommended</span>' : ''}
                    ${it.badges.is_cheapest ? '<span class="badge-pill-small pill-cheapest">🏷️ Cheapest</span>' : ''}
                    ${it.badges.is_fastest ? '<span class="badge-pill-small pill-fastest">⚡ Fastest</span>' : ''}
                    ${it.badges.is_best_baggage ? '<span class="badge-pill-small pill-baggage">🧳 Best Baggage</span>' : ''}
                `;

                html += `
                    <div class="itinerary-card" id="itin-${idx}">
                        <div class="itinerary-main">
                            <div class="airline-block">
                                <div class="itinerary-badges">${badgesHtml}</div>
                                <div class="airline-name">${it.airline.name}</div>
                                <div class="flight-no">${it.flight_number} • ${it.aircraft.model}</div>
                                <div style="font-size: 11px; color: #94A3B8; margin-top: 4px;">Source: ${it.source.toUpperCase()}</div>
                            </div>

                            <div class="flight-path">
                                <div class="time-box">
                                    <h3>${it.departure.time}</h3>
                                    <span>${it.origin.code}</span>
                                </div>

                                <div class="duration-line">
                                    <div class="duration-label">${it.duration}</div>
                                    <div class="route-bar"></div>
                                    <div class="route-stops">${it.stops === 0 ? 'Non-Stop' : it.stops + ' Stop'}</div>
                                </div>

                                <div class="time-box">
                                    <h3>${it.arrival.time}</h3>
                                    <span>${it.destination.code}</span>
                                </div>
                            </div>

                            <div class="price-booking-block">
                                <span class="price-from">Starts from</span>
                                <div class="price-amount">₹${Number(it.min_price).toLocaleString()}</div>
                                <button type="button" class="btn-view-fares" onclick="toggleFaresDrawer(${idx})">
                                    View Fares (${it.fares.length})
                                </button>
                            </div>
                        </div>

                        <!-- Fares Drawer -->
                        <div class="fares-drawer" id="drawer-${idx}" style="display: none;">
                            ${it.fares.map(fare => `
                                <div class="fare-option-card">
                                    <div>
                                        <span class="fare-badge ${fare.is_recommended ? 'badge-rec' : 'badge-saver'}">
                                            ${fare.fare_family.name}
                                        </span>
                                        <div style="font-size: 18px; font-weight: 800; color: #fff; margin: 4px 0;">
                                            ₹${Number(fare.price.final_customer_price).toLocaleString()}
                                        </div>
                                        <ul class="fare-feature-list">
                                            <li>🧳 ${fare.baggage.text}</li>
                                            <li>🔄 ${fare.refundability.is_refundable ? 'Refundable' : 'Non-Refundable'}</li>
                                            <li>✏️ ${fare.changeability.is_changeable ? 'Changes Allowed' : 'No Changes'}</li>
                                            <li>💺 ${fare.seats_available} seats remaining</li>
                                        </ul>
                                    </div>
                                    <button class="btn-select-fare" onclick="selectFare('${fare.fare_id}', '${it.flight_number}')">
                                        Select Fare
                                    </button>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            });

            document.getElementById('resultsList').innerHTML = html;
        }

        function toggleFaresDrawer(idx) {
            const drawer = document.getElementById('drawer-' + idx);
            if (drawer) {
                drawer.style.display = drawer.style.display === 'none' ? 'grid' : 'none';
            }
        }

        function selectFare(fareId, flightNo) {
            alert(`Selected Fare [${fareId}] for Flight ${flightNo}. Proceeding to checkout flow.`);
        }

        function applyClientFilters() {
            executeSearch();
        }

        // Auto execute on page load
        window.addEventListener('DOMContentLoaded', () => {
            executeSearch();
        });
    </script>
</body>
</html>
