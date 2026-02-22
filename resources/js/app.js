import './bootstrap';
import '@fortawesome/fontawesome-free/css/all.css';
import '@fortawesome/fontawesome-free/js/all.js';
import Alpine from 'alpinejs'
window.Alpine = Alpine
Alpine.start()
import AOS from 'aos';
import 'aos/dist/aos.css';

AOS.init({
    duration: 1000,
    once: true,
    easing: 'ease-in-out',
});