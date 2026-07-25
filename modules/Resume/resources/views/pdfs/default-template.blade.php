<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }} - Resume</title>
    <link href="https://fonts.googleapis.com/css2?family=Epilogue:wght@100..900&family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, "Times New Roman", serif; font-size: 14px; line-height: 1.2; margin: 0; padding: 0; color: #5a5a5a; background-color: #fff; }
        .resume { max-width: 8in; margin: 0 auto; padding: 0.4in; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .name { color: #000; font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .headline { font-style: italic; margin: 0 0 15px 0; color: #555; }
        .section { margin-bottom: 30px; }
        .section-title { 
            font-size: 16px; 
            font-weight: bold; 
            margin: 0 0 12px 0; 
            color: #000; 
            padding-bottom: 3px; 
            border-bottom: 1px solid #ddd; 
            text-transform: uppercase; 
        }
        .personal-details { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .personal-details td { padding: 3px 0; vertical-align: top; }
        .personal-details td:first-child { font-weight: bold; width: 30%; color: #000; }
        .employment-item { margin-bottom: 20px; }
        .employment-date { font-weight: 500; margin: 0 0 4px 0; font-size: 14px; color: #000; }
        .job-title { font-weight: 600; margin: 0 0 4px 0; color: #000; font-size: 14px; }
        .company { font-weight: 600; margin: 0 0 8px 0; color: #000; font-size: 14px; }
        .job-description { margin: 0 0 8px 0; }
        .achievements-title { font-weight: 600; margin: 8px 0 5px; color: #000; }
        .achievements, .skills, .hobbies { padding-left: 18px; margin: 0 0 20px 0; }
        .achievements li, .skills li, .hobbies li { margin-bottom: 4px; }
        .profile-text { margin: 0 0 25px 0; text-align: justify; line-height: 1.5; }
        .profile-image { 
            width: 80px; 
            height: 80px; 
            border-radius: 50%; 
            overflow: hidden; 
            border: 2px solid #ddd; 
        }
        .profile-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .education-major { margin: 5px 0; }
        .education-grade { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="resume">
        <!-- Header with name and photo -->
        <div class="header">
            <div>
                <div class="name">
                    {{ $resumeData['candidateName'][0]['firstName'] ?? '' }}
                    {{ $resumeData['candidateName'][0]['familyName'] ?? '' }}
                </div>
                <div class="headline">{{ $resumeData['headline'] ?? 'Professional title' }}</div>
            </div>
            @if(!empty($resumeData['profilePic']))
            <div class="profile-image">
                <img src="{{ $resumeData['profilePic'] }}" alt="Profile" />
            </div>
            @endif
        </div>

        <!-- Personal Details -->
        @if(empty($resumeData['personalDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['personalTitle'] ?? 'Personal details' }}</div>
            <table class="personal-details">
                <tr>
                    <td>Name</td>
                    <td>{{ $resumeData['candidateName'][0]['firstName'] ?? '' }} {{ $resumeData['candidateName'][0]['familyName'] ?? '' }}</td>
                </tr>
                @if(!empty($resumeData['email'][0]))
                <tr>
                    <td>Email address</td>
                    <td>{{ $resumeData['email'][0] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['phoneNumber'][0]['formattedNumber']))
                <tr>
                    <td>Phone number</td>
                    <td>{{ $resumeData['phoneNumber'][0]['formattedNumber'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['location']['formatted']))
                <tr>
                    <td>Address</td>
                    <td>{{ $resumeData['location']['formatted'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['location']['city']))
                <tr>
                    <td>City</td>
                    <td>{{ $resumeData['location']['city'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['location']['postCode']))
                <tr>
                    <td>Postcode</td>
                    <td>{{ $resumeData['location']['postCode'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['socialLinks']['github']))
                <tr>
                    <td>Github</td>
                    <td>{{ $resumeData['socialLinks']['github'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['socialLinks']['linkedin']))
                <tr>
                    <td>LinkedIn</td>
                    <td>{{ $resumeData['socialLinks']['linkedin'] }}</td>
                </tr>
                @endif
                @if(!empty($resumeData['socialLinks']['website']))
                <tr>
                    <td>Website</td>
                    <td>{{ $resumeData['socialLinks']['website'] }}</td>
                </tr>
                @endif
            </table>
        </div>
        @endif

        <!-- Profile Summary -->
        @if(!empty($resumeData['summary']['paragraph']))
        <div class="section">
            <div class="section-title">Profile</div>
            <div class="profile-text">{{ $resumeData['summary']['paragraph'] }}</div>
        </div>
        @endif

        <!-- Employment History -->
        @if(!empty($resumeData['workExperience']) && empty($resumeData['employmentDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['employmentTitle'] ?? 'Employment' }}</div>
            @foreach($resumeData['workExperience'] as $job)
            <div class="employment-item">
                <div class="employment-date">
                    {{ $job['workExperienceDates']['start']['date'] ?? '' }} - {{ $job['workExperienceDates']['end']['date'] ?? 'Present' }}
                </div>
                <div class="job-title">{{ $job['workExperienceJobTitle'] ?? '' }}</div>
                <div class="company">{{ $job['workExperienceOrganization'] ?? '' }}</div>
                @if(!empty($job['workExperienceDescription']))
                <div class="job-description">{{ $job['workExperienceDescription'] }}</div>
                @endif

                @if(!empty($job['highlights']['items']))
                <div class="achievements-title">Key Achievements</div>
                <ul class="achievements">
                    @foreach($job['highlights']['items'] as $point)
                        <li>{{ $point['bullet'] }}</li>
                    @endforeach
                </ul>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        <!-- Education -->
        @if(!empty($resumeData['education']) && empty($resumeData['educationDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['educationTitle'] ?? 'Education' }}</div>
            @foreach($resumeData['education'] as $edu)
            <div class="employment-item">
                <div class="employment-date">
                    {{ $edu['educationDates']['start']['date'] ?? '' }} - {{ $edu['educationDates']['end']['date'] ?? '' }}
                </div>
                <div class="job-title">{{ $edu['educationLevel']['label'] ?? '' }}</div>
                <div class="company">{{ $edu['educationOrganization'] ?? '' }}</div>
                
                @if(!empty($edu['educationMajor']) && is_array($edu['educationMajor']) && count($edu['educationMajor']) > 0)
                <div class="education-major">
                    <strong>Subjects:</strong> 
                    {{ implode(', ', $edu['educationMajor']) }}
                </div>
                @endif
                
                @if(!empty($edu['achievedGrade']))
                <div class="education-grade">
                    <strong>Grade:</strong> {{ $edu['achievedGrade'] }}
                </div>
                @endif
                
                @if(!empty($edu['educationDescription']))
                <div class="education-description">
                    {{ $edu['educationDescription'] }}
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        <!-- Skills -->
        @if(!empty($resumeData['skill']) && empty($resumeData['skillsDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['skillsTitle'] ?? 'Skills' }}</div>
            <ul class="skills">
                @foreach($resumeData['skill'] as $skill)
                    @if(!isset($skill['selected']) || $skill['selected'])
                        <li>{{ $skill['name'] ?? $skill }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Languages -->
        @if(!empty($resumeData['languages']) && empty($resumeData['languagesDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['languagesTitle'] ?? 'Languages' }}</div>
            <ul class="skills">
                @foreach($resumeData['languages'] as $lang)
                    <li>{{ $lang['name'] }} ({{ $lang['level'] ?? 'Fluent' }})</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Hobbies -->
        @if(!empty($resumeData['hobbies']) && empty($resumeData['hobbiesDisabled']))
        <div class="section">
            <div class="section-title">{{ $resumeData['hobbiesTitle'] ?? 'Hobbies' }}</div>
            <ul class="hobbies">
                @foreach($resumeData['hobbies'] as $hobby)
                    <li>{{ $hobby }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Custom Sections -->
        @if(!empty($resumeData['customSections']))
            @foreach($resumeData['customSections'] as $section)
                @if(!empty($section['title']) && !empty($section['content']))
                <div class="section">
                    <div class="section-title">{{ $section['title'] }}</div>
                    <div class="education-description">
                        {!! $section['content'] !!}
                    </div>
                </div>
                @endif
            @endforeach
        @endif
    </div>
</body>
</html>
