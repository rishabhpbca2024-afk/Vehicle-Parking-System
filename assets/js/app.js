/**
 * SmartPark - Frontend Interactive Script
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Live Digital Clock in Navbar
    const liveClockEl = document.getElementById('live-clock');
    if (liveClockEl) {
        const updateClock = () => {
            const now = new Date();
            const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const dateStr = now.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
            liveClockEl.textContent = `${dateStr} • ${timeStr}`;
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // Theme Switcher (Dark / Light Mode)
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');

    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('smartpark_theme', theme);
        if (themeIcon) {
            if (theme === 'light') {
                themeIcon.className = 'bi bi-sun-fill text-warning';
            } else {
                themeIcon.className = 'bi bi-moon-stars-fill text-info';
            }
        }
    };

    const currentTheme = localStorage.getItem('smartpark_theme') || 'dark';
    applyTheme(currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const activeTheme = localStorage.getItem('smartpark_theme') || 'dark';
            const newTheme = (activeTheme === 'dark') ? 'light' : 'dark';
            applyTheme(newTheme);
        });
    }

    // 2. Mobile Sidebar Toggle & Overlay
    const sidebarToggleBtn = document.getElementById('sidebar-toggle');
    const sidebar = document.querySelector('.app-sidebar');
    let overlay = document.querySelector('.sidebar-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // 3. Auto Uppercase Registration Number Inputs
    const regInputs = document.querySelectorAll('input[data-transform="uppercase"], input[name="registration_number"]');
    regInputs.forEach(input => {
        input.addEventListener('input', (e) => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = input.value.toUpperCase();
            input.setSelectionRange(start, end);
        });
    });

    // 4. Confirmation dialog for actions
    const confirmElements = document.querySelectorAll('[data-confirm]');
    confirmElements.forEach(el => {
        el.addEventListener('click', (e) => {
            const message = el.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // 5. Dynamic Slot Filtering on Vehicle Entry
    const vehicleTypeSelect = document.getElementById('entry_vehicle_type');
    const slotSelect = document.getElementById('entry_slot_id');
    const rateDisplay = document.getElementById('entry_rate_display');

    if (vehicleTypeSelect && slotSelect) {
        const filterSlotsByType = () => {
            const selectedType = vehicleTypeSelect.value;
            const options = slotSelect.querySelectorAll('option');
            let hasVisible = false;

            options.forEach(opt => {
                const optType = opt.getAttribute('data-vehicle-type');
                if (!optType || opt.value === '') {
                    // Default auto-allocate option
                    opt.style.display = '';
                } else if (optType === selectedType) {
                    opt.style.display = '';
                    hasVisible = true;
                } else {
                    opt.style.display = 'none';
                    if (opt.selected) {
                        slotSelect.value = '';
                    }
                }
            });

            // Update rate preview if rate data attributes exist
            const selectedOpt = vehicleTypeSelect.options[vehicleTypeSelect.selectedIndex];
            if (selectedOpt && rateDisplay) {
                const rate = selectedOpt.getAttribute('data-rate');
                if (rate) {
                    rateDisplay.textContent = rate;
                }
            }
        };

        vehicleTypeSelect.addEventListener('change', filterSlotsByType);
        filterSlotsByType();
    }

    // 7. Fill Default Password Helper
    const fillDefaultPassBtn = document.getElementById('fillDefaultPassBtn');
    const newUserPassInput = document.getElementById('new_user_password');
    if (fillDefaultPassBtn && newUserPassInput) {
        fillDefaultPassBtn.addEventListener('click', () => {
            newUserPassInput.value = 'Operator@123';
        });
    }
});

