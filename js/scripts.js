/*!
    * Start Bootstrap - SB UI Kit Pro v2.0.5 (https://shop.startbootstrap.com/product/sb-ui-kit-pro)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under SEE_LICENSE (https://github.com/BlackrockDigital/sb-ui-kit-pro/blob/master/LICENSE)
    */
    window.addEventListener('DOMContentLoaded', event => {
    // Activate feather when available
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }

    // Enable Bootstrap tooltips/popovers when Bootstrap JS is available
    if (window.bootstrap) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    }

    // Activate Bootstrap scrollspy for the sticky nav component
    const navStick = document.body.querySelector('#navStick');
    if (navStick) {
        if (window.bootstrap) {
            new bootstrap.ScrollSpy(document.body, {
                target: '#navStick',
                offset: 82,
            });
        }
    }

    // Collapse Navbar
    // Add styling fallback for when a transparent background .navbar-marketing is scrolled
    var navbarCollapse = function() {
        const navbarMarketingTransparentFixed = document.body.querySelector('.navbar-marketing.bg-transparent.fixed-top');
        if (!navbarMarketingTransparentFixed) {
            return;
        }
        if (window.scrollY === 0) {
            navbarMarketingTransparentFixed.classList.remove('navbar-scrolled')
        } else {
            navbarMarketingTransparentFixed.classList.add('navbar-scrolled')
        }

    };
    // Collapse now if page is not at top
    navbarCollapse();
    // Collapse the navbar when page is scrolled
    document.addEventListener('scroll', navbarCollapse);

});

// SummitCraft lightweight navigation toggle for deployments that do not load Bootstrap JS.
window.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.navbar-toggler[data-bs-target]').forEach(function (button) {
    var targetSelector = button.getAttribute('data-bs-target');
    var target = targetSelector ? document.querySelector(targetSelector) : null;
    if (!target) return;
    button.addEventListener('click', function () {
      var isShown = target.classList.toggle('show');
      button.setAttribute('aria-expanded', isShown ? 'true' : 'false');
    });
  });
});
