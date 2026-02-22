@extends('app') 
@section('content')

<main class="w-full bg-white overflow-x-hidden">
    <section class="relative w-full min-h-[85vh] flex items-center justify-center overflow-hidden py-10 md:py-20">
        
        <div class="absolute inset-0 z-0 flex items-center justify-center bg-[#7a3f91]">
            <img src="{{ asset('images/philcst-img.jpg') }}" 
                 alt="Philcst Background" 
                 class="w-full h-full object-cover md:object-contain transition-opacity duration-700">
            <div class="absolute inset-0 bg-black/50 md:bg-black/30 backdrop-brightness-90"></div>
        </div>

        <div class="relative z-10 w-[92%] max-w-4xl 
                    backdrop-blur-none bg-transparent 
                    md:backdrop-blur-lg md:bg-white/10 md:border md:border-white/20 
                    p-6 md:p-16 rounded-[2.5rem] text-center transition-all duration-500"
             data-aos="fade-up">
            
            <div class="mb-6 flex justify-center">
                <i class="fa-solid fa-graduation-cap text-6xl md:text-7xl text-[#2b0d3e] drop-shadow-md"></i>
            </div>

            <h1 class="text-[20px] md:text-xl font-bold text-[#2b0d3e] md:text-[#2b0d3e] uppercase tracking-[0.3em] mb-4 md:drop-shadow-[0_1.2px_1.2px_rgba(255,255,255,0.8)]">
                Official Alumni Platform
            </h1>

            <h1 class="text-4xl md:text-6xl font-black text-white leading-[1.1] mb-8 drop-shadow-2xl">
                Connecting Alumni<br>
                Empowering Futures
            </h1>

            <p class="text-[20px] md:text-[23px] font-medium text-gray-100 leading-relaxed max-w-3xl mx-auto drop-shadow-lg">
                The Philippine College of Science and Technology’s digital home for alumni. 
                Reconnect with batchmates, explore career opportunities, and stay connected with your alma mater.
            </p>
        </div>
    </section>
    
    <section class="py-24 px-6 w-full bg-[#ffffff]">
        <div class="max-w-[1400px] mx-auto"> 
            
            <div class="text-center mb-20" data-aos="fade-up">
                <h2 class="text-4xl md:text-5xl font-black text-[#2b0d3e] uppercase tracking-tight">Everything You Need to Stay Connected</h2>
                <p class="mt-6 text-[#7a3f91] font-bold text-xl md:text-2xl italic">A digital hub to keep alumni connected, informed, and engaged.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 lg:gap-20">

                <div class="p-10 bg-white shadow-[0_15px_50px_rgba(0,0,0,0.08)] rounded-[2rem] border-l-[10px] border-[#c59dd9] transition-all duration-500 hover:shadow-2xl"
                     data-aos="fade-left"
                     data-aos-delay="100">
                    <i class="fa-solid fa-id-badge text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase leading-tight">Alumni Profiles</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg">Update your professional and academic journey with our secure alumni profiles.</p>
                </div>

                <div class="p-10 bg-white shadow-[0_15px_50px_rgba(0,0,0,0.08)] rounded-[2rem] border-l-[10px] border-[#7a3f91] transition-all duration-500 hover:shadow-2xl"
                     data-aos="fade-left"
                     data-aos-delay="300">
                    <i class="fa-solid fa-calendar-check text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase leading-tight">Events & Reunions</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg">Never miss campus events, batch reunions, and workshops.</p>
                </div>

                <div class="p-10 bg-white shadow-[0_15px_50px_rgba(0,0,0,0.08)] rounded-[2rem] border-l-[10px] border-[#2b0d3e] transition-all duration-500 hover:shadow-2xl"
                     data-aos="fade-left"
                     data-aos-delay="500">
                    <i class="fa-solid fa-briefcase text-5xl text-[#7a3f91] mb-8"></i>
                    <h3 class="text-2xl font-black text-[#2b0d3e] mb-4 uppercase leading-tight">Job Opportunities</h3>
                    <p class="text-gray-500 font-medium leading-relaxed italic text-lg">Discover career opportunities posted by alumni and partner companies.</p>
                </div>

            </div>
        </div>
    </section>

    <footer class="bg-[#2b0d3e] text-white pt-24 pb-12 px-6">
        <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-16 border-b border-white/10 pb-16 mb-12 text-center md:text-left">
            <div class="space-y-6">
                <h3 class="text-3xl font-black text-[#c59dd9] uppercase italic tracking-tighter">PHILCST Alumni Connect</h3>
                <p class="text-lg text-white/70 italic uppercase font-bold leading-relaxed">Empowering alumni through technology.</p>
            </div>

            <div class="space-y-6">
                <h4 class="text-[#c59dd9] font-black uppercase text-sm tracking-widest">Our Location</h4>
                <p class="flex items-start justify-center md:justify-start gap-4 text-lg text-white/80 uppercase font-medium">
                    <i class="fa-solid fa-location-dot text-[#c59dd9] text-2xl mt-1"></i>
                    <span>Old Nalsian Road, Nalsian, Calasiao, Philippines, 2418</span>
                </p>
                <div class="space-y-4 text-lg italic text-white/80 font-medium">
                    <p class="flex items-center justify-center md:justify-start gap-4">
                        <i class="fa-solid fa-phone text-[#c59dd9]"></i> (075) 522 8032
                    </p>
                    <p class="flex items-center justify-center md:justify-start gap-4">
                        <i class="fa-solid fa-envelope text-[#c59dd9]"></i> philcstreg@yahoo.com
                    </p>
                </div>
            </div>

            <div class="space-y-6">
                <h4 class="text-[#c59dd9] font-black uppercase text-sm tracking-widest">Social Connect</h4>
                <div class="flex items-center justify-center md:justify-start space-x-4 bg-white/5 p-4 rounded-2xl hover:bg-white/10 transition-all cursor-pointer">
                    <i class="fa-brands fa-facebook text-4xl text-[#c59dd9]"></i>
                    <span class="font-black text-xs md:text-sm uppercase italic leading-tight">Philippine College of Science and Technology</span>
                </div>
            </div>
        </div>
        <div class="text-center text-xs tracking-[0.4em] text-white/40 uppercase font-black">
            © 2026 PHILCST. All Rights Reserved.
        </div>
    </footer>
</main>

<style>
    /* Professional Smooth Scroll */
    html {
        scroll-behavior: smooth;
    }

    body {
        margin: 0;
        padding: 0;
        background-color: #ffffff;
    }

    /* Modern Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    ::-webkit-scrollbar-track {
        background: #f8fafc;
    }
    ::-webkit-scrollbar-thumb {
        background: #2b0d3e;
        border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #7a3f91;
    }
</style>

@endsection