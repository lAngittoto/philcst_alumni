@extends('layouts.public')
@section('content')
@include('layouts.header')

<style>
    html { scroll-behavior: smooth; }
    body { background-color: #7a3f91; margin: 0; padding: 0; overflow-x: hidden; }
    .prof-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
</style>

<main class="font-sans antialiased text-[#2b0d3e]">

    <section class="bg-[#7a3f91] py-12 md:py-24 px-4 md:px-6 relative overflow-hidden">
        <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-10 relative z-10 mt-20">
            
            <div class="group bg-white/10 border border-white/20 p-8 md:p-16 lg:p-20 rounded-3xl backdrop-blur-md shadow-2xl transition-all duration-300 hover:-translate-y-2 hover:bg-white/[0.15]">
                <div class="mb-6 inline-block">
                    <h2 class="text-3xl sm:text-4xl md:text-5xl font-black text-white tracking-tight">
                        MISSION
                    </h2>
                    <div class="h-1.5 w-12 bg-purple-400 mt-2 rounded-full transition-all duration-500 group-hover:w-24"></div>
                </div>

                <p class="text-base sm:text-lg md:text-xl text-gray-100 leading-relaxed font-light">
                    PhilCST provides quality education to students who are imbued with strong moral character through a well-balanced research and community-oriented learning environment that develops critical thinking for maximum development of individual talents and capabilities.
                </p>
            </div>

            <div class="group bg-white/10 border border-white/20 p-8 md:p-16 lg:p-20 rounded-3xl backdrop-blur-md shadow-2xl transition-all duration-300 hover:-translate-y-2 hover:bg-white/[0.15]">
                <div class="mb-6 inline-block">
                    <h2 class="text-3xl sm:text-4xl md:text-5xl font-black text-white tracking-tight">
                        VISION
                    </h2>
                    <div class="h-1.5 w-12 bg-purple-400 mt-2 rounded-full transition-all duration-500 group-hover:w-24"></div>
                </div>

                <p class="text-base sm:text-lg md:text-xl text-gray-100 leading-relaxed font-light">
                    PhilCST envisions producing graduates fully equipped with knowledge, values, and skills who are globally competitive in their profession and ever ready to render quality services.
                </p>
            </div>

        </div>
    </section>

    <section class="max-w-7xl mx-auto px-4 sm:px-8 py-20 md:py-32">
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