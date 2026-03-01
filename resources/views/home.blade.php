@extends('layouts.public') 
@section('content')
@include('layouts.header')

<main class="w-full bg-white overflow-x-hidden">
    
    <section class="relative w-full bg-white flex flex-col items-center">
        <div class="w-full h-[50vh] md:h-[80vh] overflow-hidden">
            <img src="{{ asset('images/philcst-img.jpg') }}" 
                 alt="Philcst Background" 
                 class="w-full h-full object-cover md:object-contain"
                 data-aos="fade-in" data-aos-duration="1500">
        </div>
    </section>

    <section class="relative z-10 py-16 md:py-24 bg-white">
        <div class="max-w-5xl mx-auto px-6 text-center">
            
            <div class="inline-block mb-10">
                <span class="text-[#7a3f91] text-xs md:text-sm font-bold uppercase tracking-[0.4em]">
                    Official Alumni Platform
                </span>
                <div class="w-12 h-0.5 bg-[#7a3f91] mx-auto mt-3"></div>
            </div>

            <h2 class="text-4xl md:text-6xl font-black text-[#2b0d3e] leading-tight mb-8 tracking-tight" 
                data-aos="fade-up" data-aos-delay="200">
                Connecting Alumni.<br>
                <span class="text-[#7a3f91]">Empowering Futures.</span>
            </h2>

            <p class="text-xl md:text-2xl font-medium text-gray-500 leading-relaxed max-w-3xl mx-auto" 
               data-aos="fade-up" data-aos-delay="400">
                The Philippine College of Science and Technology’s digital home for alumni. 
                Reconnect with batchmates, explore career opportunities, and stay connected with your alma mater.
            </p>
        </div>
    </section>
    
    <section class="pb-32 px-6 w-full bg-white">
        <div class="max-w-[1400px] mx-auto"> 
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-16 text-center">

                <div class="flex flex-col items-center" data-aos="fade-up" data-aos-delay="100">
                    <i class="fa-solid fa-id-badge text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase tracking-tight">Alumni Profiles</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg md:text-xl max-w-sm">
                        Update your professional and academic journey with our secure alumni profiles.
                    </p>
                </div>

                <div class="flex flex-col items-center" data-aos="fade-up" data-aos-delay="200">
                    <i class="fa-solid fa-calendar-check text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase tracking-tight">Events & Reunions</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg md:text-xl max-w-sm">
                        Never miss campus events, batch reunions, and workshops.
                    </p>
                </div>

                <div class="flex flex-col items-center" data-aos="fade-up" data-aos-delay="300">
                    <i class="fa-solid fa-briefcase text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase tracking-tight">Job Opportunities</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg md:text-xl max-w-sm">
                        Discover career opportunities posted by alumni and partner companies.
                    </p>
                </div>

            </div>
        </div>
    </section>

</main>

@include('layouts.footer')

<style>
    /* Professional Smooth Scroll */
    html {
        scroll-behavior: smooth;
        -webkit-font-smoothing: antialiased;
    }

    body {
        margin: 0;
        padding: 0;
        background-color: #ffffff;
    }

    /* Minimalist Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    ::-webkit-scrollbar-track {
        background: white;
    }
    ::-webkit-scrollbar-thumb {
        background: #2b0d3e;
        border-radius: 10px;
    }

    /* Smooth AOS Transitions (Non-distracting) */
    [data-aos] {
        transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    }
</style>

@endsection