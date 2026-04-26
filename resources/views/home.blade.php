@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }
    body { margin: 0; padding: 0; background-color: #F5F5F5; }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: #F5F5F5; }
    ::-webkit-scrollbar-thumb { background: #333333; border-radius: 10px; }

    [data-aos] {
        transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    }
</style>

<main class="w-full mt-8 overflow-x-hidden bg-[#F5F5F5]">

    {{-- ══ HERO IMAGE ══ --}}
    <section class="relative w-full flex flex-col items-center bg-[#F5F5F5]">
        <div class="w-full h-[50vh] md:h-[80vh] overflow-hidden">
            <img src="{{ asset('images/philcst-img.jpg') }}"
                 alt="PhilCST Background"
                 class="w-full h-full object-cover md:object-contain"
                 data-aos="fade-in" data-aos-duration="1500">
        </div>
    </section>

    {{-- ══ HERO TEXT ══ --}}
    <section class="relative z-10 py-16 md:py-24 bg-[#F5F5F5]">
        <div class="max-w-5xl mx-auto px-6 text-center">

            <div class="inline-block mb-10" data-aos="fade-up" data-aos-delay="0">
                <span class="font-sans font-bold text-sm uppercase tracking-[0.35em] text-[#7a3f91] block">Official Alumni Platform</span>
                <div class="w-12 h-0.5 bg-[#7a3f91] mx-auto mt-3"></div>
            </div>

            <h2 class="font-sans font-bold text-3xl md:text-5xl uppercase leading-tight text-[#333333] mb-8"
                data-aos="fade-up" data-aos-delay="200">
                Connecting Alumni.<br>
                <span class="text-[#7a3f91]">Empowering Futures.</span>
            </h2>

            <p class="font-sans font-normal text-xl leading-relaxed text-[#333333] mx-auto"
               style="max-width:44rem;"
               data-aos="fade-up" data-aos-delay="400">
                The Philippine College of Science and Technology's digital home for alumni.
                Reconnect with batchmates, explore career opportunities, and stay connected with your alma mater.
            </p>

        </div>
    </section>

    {{-- ══ DIVIDER ══ --}}
    <div class="px-6 bg-[#F5F5F5]">
        <div class="max-w-5xl mx-auto border-t-2 border-[#e0e0e0]"></div>
    </div>

    {{-- ══ FEATURE CARDS ══ --}}
    <section class="py-16 pb-32 px-6 w-full bg-[#F5F5F5]">
        <div class="max-w-5xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

                {{-- Feature Card 1 --}}
                <div class="bg-white border-2 border-[#e0e0e0] rounded-2xl p-8 flex flex-col items-center text-center transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="100">
                    <div class="w-16 h-16 rounded-full bg-[#F5F5F5] flex items-center justify-center mb-6">
                        <i class="fa-solid fa-id-badge text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4">Alumni Profiles</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                        Update your professional and academic journey with our secure alumni profiles.
                    </p>
                </div>

                {{-- Feature Card 2 --}}
                <div class="bg-white border-2 border-[#e0e0e0] rounded-2xl p-8 flex flex-col items-center text-center transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="200">
                    <div class="w-16 h-16 rounded-full bg-[#F5F5F5] flex items-center justify-center mb-6">
                        <i class="fa-solid fa-calendar-check text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4">Events &amp; Reunions</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                        Stay updated on campus events, reunions, and alumni activities.
                    </p>
                </div>

                {{-- Feature Card 3 --}}
                <div class="bg-white border-2 border-[#e0e0e0] rounded-2xl p-8 flex flex-col items-center text-center transition-all duration-300"
                     data-aos="fade-up" data-aos-delay="300">
                    <div class="w-16 h-16 rounded-full bg-[#F5F5F5] flex items-center justify-center mb-6">
                        <i class="fa-solid fa-briefcase text-3xl text-[#7a3f91]"></i>
                    </div>
                    <h3 class="font-sans font-bold text-2xl uppercase text-[#333333] mb-4">Job Opportunities</h3>
                    <p class="font-sans font-normal text-xl text-[#333333] leading-relaxed">
                        Explore available job opportunities shared through the system.
                    </p>
                </div>

            </div>
        </div>
    </section>

</main>

@include('layouts.footer')

@endsection