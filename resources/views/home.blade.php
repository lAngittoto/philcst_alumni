@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }
    body { margin: 0; padding: 0; background-color: #FFFFFF; }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #FFFFFF; }
    ::-webkit-scrollbar-thumb { background: #333333; border-radius: 10px; }

    [data-aos] {
        transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    }

    /* Bounce reveal for feature card text (title + paragraph) */
    .reveal-bounce {
        opacity: 0;
        transform: translateY(28px) scale(0.90);
        transition: opacity 0.65s cubic-bezier(0.34, 1.56, 0.64, 1),
                    transform 0.65s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .reveal-bounce.is-visible {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    /* Glassy feature cards — true glass look */
    .feature-card {
        position: relative;
        background: linear-gradient(135deg, rgba(255,255,255,0.35), rgba(255,255,255,0.10));
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        border: 1.5px solid rgba(255, 255, 255, 0.55);
        box-shadow: 0 8px 32px 0 rgba(122, 63, 145, 0.15),
                    inset 0 1px 1px 0 rgba(255, 255, 255, 0.6);
    }
    .icon-circle {
        background: rgba(122, 63, 145, 0.10);
        border: 1px solid rgba(122, 63, 145, 0.15);
    }
</style>

<main class="w-full mt-8 overflow-x-hidden bg-white">

    {{-- ══ HERO IMAGE ══ --}}
    <section class="relative w-full flex flex-col items-center bg-white">
        <div class="w-full h-[50vh] md:h-[80vh] overflow-hidden">
            <img src="{{ asset('images/philcst-img.jpg') }}"
                 alt="PhilCST Background"
                 class="w-full h-full object-cover md:object-contain"
                 data-aos="fade-in" data-aos-duration="1500">
        </div>
    </section>

    {{-- ══ HERO TEXT ══ --}}
    <section class="relative z-10 py-16 md:py-24 bg-white">
        <div class="max-w-5xl mx-auto px-6 text-center">

            <div class="inline-block mb-10 reveal-bounce" data-reveal style="transition-delay:0s">
                <span class="font-sans font-bold text-3xl uppercase tracking-[0.15em] text-[#333333] block">Alumni Connect</span>
                <div class="w-12 h-0.5 bg-[#7a3f91] mx-auto mt-3"></div>
            </div>

            <h2 class="font-sans font-bold text-3xl md:text-5xl uppercase leading-tight text-[#333333] mb-8 reveal-bounce" data-reveal style="transition-delay:0.15s">
                Connecting Alumni.<br>
                <span class="text-[#7a3f91]">Empowering Futures.</span>
            </h2>

            <p class="font-sans font-normal text-xl leading-relaxed text-[#333333] mx-auto reveal-bounce"
               style="max-width:44rem; transition-delay:0.3s" data-reveal>
                The official alumni platform of the Philippine College of Science and Technology, designed to strengthen connections among graduates, provide career opportunities, and foster lifelong engagement with the institution.
            </p>

        </div>
    </section>

    {{-- ══ DIVIDER ══ --}}
    <div class="px-6 bg-white">
        <div class="max-w-5xl mx-auto border-t-2 border-[#e0e0e0]"></div>
    </div>

    {{-- ══ FEATURE CARDS ══ --}}
    <section class="py-16 pb-32 px-6 w-full bg-white">
        <div class="max-w-5xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

                {{-- Feature Card 1 --}}
                <div class="feature-card rounded-2xl p-8 flex flex-col items-center text-center"
                     data-aos="fade-up" data-aos-duration="700" data-aos-delay="0" data-aos-easing="ease-out-cubic">
                    <div class="icon-circle w-16 h-16 rounded-full flex items-center justify-center mb-6">
                        <i class="fa-solid fa-id-badge text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4 reveal-bounce" data-reveal>Alumni Network</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed reveal-bounce" data-reveal>
                        Connect and reconnect with fellow PhilCST graduates, build professional relationships, and expand your alumni network.
                    </p>
                </div>

                {{-- Feature Card 2 --}}
                <div class="feature-card rounded-2xl p-8 flex flex-col items-center text-center"
                     data-aos="fade-up" data-aos-duration="700" data-aos-delay="150" data-aos-easing="ease-out-cubic">
                    <div class="icon-circle w-16 h-16 rounded-full flex items-center justify-center mb-6">
                        <i class="fa-solid fa-calendar-check text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4 reveal-bounce" data-reveal>Events &amp; Activities</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed reveal-bounce" data-reveal>
                        Stay informed about alumni homecomings, reunions, seminars, and other activities organized by the institution.
                    </p>
                </div>

                {{-- Feature Card 3 --}}
                <div class="feature-card rounded-2xl p-8 flex flex-col items-center text-center"
                     data-aos="fade-up" data-aos-duration="700" data-aos-delay="300" data-aos-easing="ease-out-cubic">
                    <div class="icon-circle w-16 h-16 rounded-full flex items-center justify-center mb-6">
                        <i class="fa-solid fa-briefcase text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4 reveal-bounce" data-reveal>Career Opportunities</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed reveal-bounce" data-reveal>
                        Discover job openings, career opportunities, and professional growth resources shared through the platform.
                    </p>
                </div>

            </div>
        </div>
    </section>

</main>

<script>
    function initRevealBounce() {
        // All reveal-bounce elements (hero text + card text) replay
        // every time they enter/leave the viewport, scrolling either direction.
        const bounceTargets = document.querySelectorAll('.reveal-bounce[data-reveal]');
        const bounceObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                } else {
                    entry.target.classList.remove('is-visible');
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });

        bounceTargets.forEach((el) => {
            bounceObserver.observe(el);
            // Safety net: reveal immediately if already in/near viewport
            // right when observing starts (e.g. right after a Livewire
            // SPA navigation where the section is instantly on screen).
            const rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) {
                el.classList.add('is-visible');
            }
        });
    }

    // Normal full page load
    document.addEventListener('DOMContentLoaded', initRevealBounce);

    // Livewire / wire:navigate SPA-style navigation doesn't re-fire
    // DOMContentLoaded, so re-init after every navigation as well.
    document.addEventListener('livewire:navigated', initRevealBounce);
</script>

@include('layouts.footer')

@endsection