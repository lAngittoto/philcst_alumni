import './bootstrap';
import '@fortawesome/fontawesome-free/css/all.css';
import '@fortawesome/fontawesome-free/js/all.js';
import AOS from 'aos';
import 'aos/dist/aos.css';
import Alpine from 'alpinejs';

// Initialize AOS FIRST
AOS.init({
    duration: 1000,
    once: true,
    easing: 'ease-in-out',
});

// Configure Alpine BEFORE starting
Alpine.configure({
    timeout: 1000,
});

// Expose and start Alpine
window.Alpine = Alpine;
Alpine.start();

// Re-initialize Alpine after Livewire updates
document.addEventListener('livewire:updated', () => {
    Alpine.flushAndStopDeferringMacros();
    Alpine.start();
});

document.addEventListener('livewire:navigated', () => {
    Alpine.flushAndStopDeferringMacros();
    Alpine.start();
});