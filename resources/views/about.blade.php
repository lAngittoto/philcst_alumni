@extends('layouts.public')
@section('content')
@include('layouts.header')
<style>
    html { scroll-behavior: smooth; }
    body { background-color: #7a3f91; margin: 0; padding: 0; overflow-x: hidden; }
    .prof-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
</style>

<main class="font-sans antialiased text-[#2b0d3e]">

    <section class="min-h-[70vh] md:min-h-[85vh] w-full flex items-center justify-center px-4 sm:px-6 pt-10 pb-20"> 
        <div class="w-full max-w-6xl bg-[#ffffff] prof-shadow rounded-[2rem] md:rounded-[3rem] overflow-hidden" 
             data-aos="zoom-in" 
             data-aos-duration="1500">
            
            <div class="hidden md:block">
                <img src="{{ asset('images/mission-vision.jpg') }}" 
                     alt="Mission and Vision" 
                     class="w-full h-auto object-contain">
            </div>

            <div class="block md:hidden p-8 space-y-10 text-center">
                <div class="space-y-3">
                    <h2 class="text-[#7a3f91] text-3xl font-black uppercase tracking-tighter">Mission</h2>
                    <div class="h-1 w-12 bg-[#7a3f91] mx-auto rounded-full"></div>
                    <p class="text-lg font-bold leading-relaxed text-[#2b0d3e]">
                        PhilCST provides quality education to students who are imbued with strong moral character through a well-balanced research and community oriented learning environment that develops critical thinking for maximum development of individual talents and capabilities.
                    </p>
                </div>

                <hr class="border-gray-100">

                <div class="space-y-3">
                    <h2 class="text-[#7a3f91] text-3xl font-black uppercase tracking-tighter">Vision</h2>
                    <div class="h-1 w-12 bg-[#7a3f91] mx-auto rounded-full"></div>
                    <p class="text-lg font-bold leading-relaxed text-[#2b0d3e]">
                        PhilCST envision to produce graduates fully equipped with knowledge, values, and skills and who are globally competitive in their profession ever ready to render quality services.
                    </p>
                </div>
            </div>

        </div>
    </section>

    <section class="max-w-7xl mx-auto px-4 sm:px-8 pb-32">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 md:gap-16 items-start">
            
            <article class="space-y-8" data-aos="fade-up">
                <div class="bg-[#ffffff] p-8 md:p-14 rounded-[2.5rem] md:rounded-[3rem] prof-shadow">
                    <div class="inline-block px-5 py-1.5 bg-[#f2eaf7] rounded-full mb-6">
                        <span class="text-[#7a3f91] text-xs font-black uppercase tracking-[0.2em]">Our Heritage</span>
                    </div>
                    
                    <h3 class="text-3xl md:text-4xl font-black uppercase text-[#7a3f91] mb-8 tracking-tighter leading-none">
                        History & Foundation
                    </h3>
                    
                    <div class="space-y-6 text-[#2b0d3e]/80 leading-relaxed text-base md:text-lg">
                        <p class="font-bold text-[#2b0d3e] text-xl md:text-2xl tracking-tight leading-tight">
                            The Philippine College of Science and Technology (PHILCST) is a private, non-sectarian institution of higher learning.
                        </p>
                        <p>
                            It was established in 1994 by Mrs. Lourdes S. Fernandez as a response to the community’s need for quality education following the devastation of the 1990 Dagupan earthquake.
                        </p>
                        
                        <blockquote class="relative p-6 md:p-8 bg-[#f2eaf7] rounded-2xl md:rounded-3xl border-l-[8px] border-[#7a3f91]">
                            <p class="text-[#2b0d3e] italic font-black text-lg md:text-xl">
                                "Since beginning formal operations in June 1994, PHILCST has expanded its facilities and academic offerings to develop globally competitive graduates."
                            </p>
                        </blockquote>
                    </div>
                </div>
            </article>

            <aside class="grid grid-cols-1 gap-6 md:gap-8" data-aos="fade-up">
                <div class="prof-shadow rounded-[2rem] overflow-hidden bg-[#ffffff]">
                    <img src="{{ asset('images/school.jpg') }}" class="w-full h-64 md:h-[400px] object-cover hover:scale-105 transition-transform duration-700 block">
                </div>
                <div class="prof-shadow rounded-[2rem] overflow-hidden bg-[#ffffff]">
                    <img src="{{ asset('images/school-1.jpg') }}" class="w-full h-64 md:h-[400px] object-cover hover:scale-105 transition-transform duration-700 block">
                </div>
            </aside>
        </div>
    </section>

</main>

<div class="bg-[#ffffff]">
    @include('layouts.footer')
</div>
@endsection