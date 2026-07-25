<?php

namespace Modules\Resume\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Resume\Models\Resume;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
// use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
// use Modules\Resume\Models\GettingStartedStep;

class ResumeController extends Controller
{

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function createEmpty(Request $request)
    {
        $request->validate([
            'newEmptyResume' => 'required',
        ]);

        $newEmptyResume = $request->newEmptyResume;

        // Create a new resume
        $resume = Resume::create([
            'user_id' => auth()->id(),
            'title' => 'My Resume',
            'cv_resumejson' => $newEmptyResume,
        ]);
        

        // GettingStartedStep::where('user_id', auth()->id())
        // ->update(['first_cv' =>x true]);
    

        return response()->json([
            'success' => true,
            'data' => $resume
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        
        $resume = Resume::findOrFail($id);
        if($resume->user_id == Auth::user()->id){
            return response()->json([
                'success' => true,
                'data' => $resume
            ]);
        }else{
            return response()->json([
                'success' => false,
                'message' => "Required CV Not Found!"
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'title' => 'nullable',
            'cv_resumejson' => 'nullable',
        ]);

        $cv_resumejson = $request->cv_resumejson;

        $resume = Resume::findOrFail($id);
        if($request->cv_resumejson){
            $resume->cv_resumejson = $cv_resumejson;
        }
        if($request->title){
            $resume->title = $request->title;
        }
        $cv_resumejson = $request->cv_resumejson ?? $resume->cv_resumejson;

        $finalTitle = $request->title ?? $resume->title;

        if ($finalTitle === "My Resume") {

            $candidateName = $cv_resumejson['candidateName'][0] ?? null;
            $headline = $cv_resumejson['headline'] ?? null;

            if ($candidateName && isset($candidateName['firstName']) && isset($candidateName['familyName']) && $headline) {

                $headlineWords = array_slice(explode(' ', $headline), 0, 10);
                $headline = implode(' ', $headlineWords);

                $resume->title = $candidateName['firstName'].' '.$candidateName['familyName'].' | '.$headline;

            } elseif ($candidateName && isset($candidateName['firstName']) && isset($candidateName['familyName'])) {

                $resume->title = $candidateName['firstName'].' '.$candidateName['familyName'];

            } elseif ($candidateName && isset($candidateName['firstName'])) {

                $resume->title = $candidateName['firstName'];
            }
        }

        $resume->save();

        // Log activity
        // CvRecentActivity::updateOrCreate(
        //     [
        //         'user_id' => auth()->id(),
        //         'type' => 'resume',
        //         'type_id' => $resume->id,
        //     ],
        //     [
        //     'message' => 'Worked on a resume!',
        //     'ip_address' => request()->ip(),
        //     'user_agent' => request()->userAgent(),
        // ]);

        return response()->json([
            'success' => true,
            'data' => $resume
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
{
    $resume = Resume::findOrFail($id);
    if($resume->user_id != Auth::user()->id) {

        return response()->json([
            'success' => false,
            'message' => 'Cannot delete this resume.',
        ], 403);
    }

    // Delete the resume
    $resume->delete();

    // Delete the related activity
    // CvRecentActivity::where('user_id', Auth::user()->id)
    //     ->where('type_id', $id)
    //     ->where('type', 'resume')
    //     ->delete();

    // Return paginated recent activities
    
    $perPage = request()->per_page ?? 3;
    $page = request()->page ?? 1;
    
   $resume = Resume::where('user_id', Auth::user()->id)
   ->paginate($perPage, ['*'], 'page', $page);

    return response()->json([
        'success' => true,
        'message' => 'Resume deleted successfully',
        'data' => [
            'data' => $resume,
            'total' => $resume->total,
            'per_page' => (int)$perPage,
            'current_page' => (int)$page,
            'last_page' => ceil($resume->total / $perPage)
        ]
    ]);
}




    /**
 * Helper method to extract text from any element
 */
    private function extractTextFromElement($element)
    {
        $text = '';
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
        } elseif (method_exists($element, 'getText')) {
            $text .= $element->getText();
        }
        
        return $text;
    }

    public function parseResumeOCRPyScript(Request $request)
    {

        $request->validate([
            'file' => 'required|mimes:pdf,png,jpg,jpeg,docx'
        ]);
        // return ("new - OCR script");
        $model = $request->model ?? 'gpt-4o-mini';
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = storage_path('app/temp/' . $originalName);
        $file->move(storage_path('app/temp'), $originalName);

        $cleanOutput = '';

        if ($extension == 'docx') {
            // Handle DOCX files using PhpOffice\PhpWord
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
                $text = '';
                
                foreach ($phpWord->getSections() as $section) {
                    $elements = $section->getElements();
                    
                    foreach ($elements as $element) {
                        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                            // Handle TextRun elements
                            foreach ($element->getElements() as $textElement) {
                                if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $text .= $textElement->getText();
                                }
                            }
                            $text .= "\n"; // Add newline after each TextRun
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                            // Handle direct Text elements
                            $text .= $element->getText() . "\n";
                        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                            // Handle tables
                            foreach ($element->getRows() as $row) {
                                foreach ($row->getCells() as $cell) {
                                    $text .= $this->extractTextFromElement($cell) . "\t";
                                }
                                $text .= "\n";
                            }
                        }
                    }
                }
                
                $cleanOutput = mb_convert_encoding(trim($text), 'UTF-8', 'UTF-8');
                
            } catch (\Exception $e) {
                \Log::error('Error processing DOCX file', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $path
                ]);
                return response()->json([
                    'error' => 'Failed to process DOCX file',
                    'details' => $e->getMessage()
                ], 500);
            }
        } else {
                try {
                        /** -------------------------------
                         *  1. Attempt native PDF parsing
                         * -------------------------------- */
                        $parser = new Parser();
                        $pdf = $parser->parseFile($path);
                        $cleanOutput = trim($pdf->getText());

                        Log::info('PDF text extraction attempt completed', [
                            'text_length' => strlen($cleanOutput)
                        ]);

                        $ocrEnabled = filter_var(env('OCR_ENABLED', true), FILTER_VALIDATE_BOOLEAN);

                        /** ------------------------------------------------
                         *  2. Fallback to OCR if needed
                         * ------------------------------------------------ */
                        if ((empty($cleanOutput) || strlen($cleanOutput) < 100)) {

                            if (!$ocrEnabled) {
                                Log::warning('OCR disabled and PDF extraction insufficient', [
                                    'text_length' => strlen($cleanOutput)
                                ]);

                                throw new \Exception('PDF text extraction failed and OCR is disabled');
                            }

                            Log::info('Falling back to OCR processing');

                            $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
                            $scriptPath = public_path('scripts/parse_resume.py');

                            if (!file_exists($scriptPath)) {
                                Log::error('OCR script not found', [
                                    'script_path' => $scriptPath
                                ]);
                                throw new \Exception('OCR script not found');
                            }

                            if (!is_executable($pythonPath)) {
                                Log::error('Python executable not found or not executable', [
                                    'python_path' => $pythonPath
                                ]);
                                throw new \Exception('Invalid Python path');
                            }

                            $command = sprintf(
                                '%s %s %s',
                                escapeshellarg($pythonPath),
                                escapeshellarg($scriptPath),
                                escapeshellarg($path)
                            );

                            Log::debug('Executing OCR command', [
                                'command' => $command
                            ]);

                            $output = shell_exec($command . ' 2>&1');

                            if ($output === null) {
                                Log::error('OCR execution failed (null output)');
                                throw new \Exception('Failed to execute OCR script');
                            }

                            $cleanOutput = mb_convert_encoding(trim($output), 'UTF-8', 'UTF-8');

                            Log::info('OCR processing completed', [
                                'ocr_text_length' => strlen($cleanOutput)
                            ]);

                            if (empty($cleanOutput)) {
                                Log::error('OCR returned empty output');
                                throw new \Exception('OCR processing returned no text');
                            }
                        }

                        Log::info('Resume parsing successful');

                        // return response()->json([
                        //     'success' => true,
                        //     'text' => $cleanOutput
                        // ]);

                    } catch (\Throwable $e) {

                        Log::error('Resume parsing failed', [
                            'file' => $path,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        return response()->json([
                            'success' => false,
                            'error' => 'Failed to process resume',
                            'details' => env('APP_DEBUG') ? $e->getMessage() : null
                        ], 400);
                    }
        }

        try {
            $apiKey = config('services.openai.api_key');
            $style_adjective = $request->languageStyle ?? "Friendly";
            $job_description = $request->additionalInfo ?? "";          
        
            // Construct detailed evaluation prompt based on the framework
            $Systemprompt = <<<PROMPT
                ROLE
                - You read the raw CV text from the user.
                - You analyze the contents and elaborate / expand where this may be lacking
                - You output ONE valid JSON object conforming to the SCHEMA below.
                - You enrich the CV to be recruiter-friendly and evidence-anchored without inventing data.
                
                CORE RULES
                1) Output JSON only — no extra text.
                2) Preserve all stated facts (names, dates, metrics, employers).
                3) Never invent new numbers, employers, degrees, or certifications.
                4) If >40% of a summary or bullet is generalized wording, set "confidence":"inferred"; otherwise "stated".
                5) Use {$style_adjective} language style.---
                6) UK spelling and date formats (e.g., Mar 2023 – Jul 2025).
                7) Consistent tense and formatting.
                
                SUMMARY
                - Produce ONE cohesive paragraph (80–130 words).
                - Use strong verbs (Led, Built, Designed, Delivered, Optimized).
                - Cover, where relevant: technical/domain scope, scalability/performance, collaboration/leadership, quality/security/UX.
                - Reuse stated metrics verbatim (e.g. “improved performance by 30%”).
                - No fabricated metrics.

                WorkExperience
                - workExperienceDescription Should be atleast (100–150 words).
                

                BULLET DECOMPOSITION (REINFORCED)
                - Every experience entry MUST have *3–7 bullets*. Fewer than 3 is INVALID.
                - If duties appear as a single sentence, *DECOMPOSE* into discrete bullets that each cover one facet:
                  (a) what was built/delivered,
                  (b) integrations/security,
                  (c) performance/scalability (reuse stated metrics),
                  (d) collaboration/leadership/delivery,
                  (e) quality/testing/reliability,
                  (f) architecture/tooling.
                - Each bullet is *one concise ATS-friendly sentence* and starts with a strong verb.
                - Avoid combining multiple facets into one bullet.
                - For generic expansions, set "confidence":"inferred".
                
                BULLET DECOMPOSITION EXAMPLES
                SOURCE:
                "Developed REST APIs, integrated third-party services, and managed databases with focus on optimisation and scalability."
                TARGET:
                - Designed and developed RESTful APIs powering customer-facing web and mobile applications. (inferred)
                - Integrated third-party services with secure, reliable data exchange and webhook handling. (inferred)
                - Optimized queries and caching to improve average API response time by ~35% where measured. (stated if present)
                - Implemented asynchronous jobs and queues to maintain responsiveness under heavy load. (inferred)
                - Collaborated with product and QA to deliver production-ready features on predictable timelines. (inferred)
                                
                **Parse the CV**  
                Analyze the candidate's CV and extract structured information in the following JSON format. Fill as many fields as possible based on the text.
                
                
                ### REQUIRED JSON FORMAT:
                {
                "data": {
                "candidateName": [
                {
                "firstName": "",
                "familyName": ""
                }
                ],
                "headline": "",
                "website": null,
                "preferredWorkLocation": null,
                "willingToRelocate": null,
                "objective": null,
                "association": null,
                "hobby": null,
                "patent": null,
                "publication": null,
                "referee": null,
                "dateOfBirth": null,
                "headshot": null,
                "nationality": null,
                "email": [""],
                "phoneNumber": [
                {
                "rawText": "",
                "countryCode": "",
                "nationalNumber": "",
                "formattedNumber": "",
                "internationalCountryCode": ""
                }
                ],
                "location": {
                "city": "",
                "state": "",
                "poBox": null,
                "street": null,
                "country": "",
                "latitude": null,
                "formatted": "",
                "longitude": null,
                "rawInput": "",
                "stateCode": "",
                "postalCode": null,
                "countryCode": "",
                "streetNumber": null,
                "apartmentNumber": null
                },
                "availability": null,
                "summary": {
                    "paragraph": "",
                    "years_experience": null,
                    "confidence": "stated"
                },
                "expectedSalary": null,
                "education": [
                {
                "educationAccreditation": "",
                "educationOrganization": "",
                "educationDates": {
                  "end": {
                    "day": null,
                    "date": "",
                    "year": null,
                    "month": null,
                    "isCurrent": false
                  },
                  "start": {
                    "day": null,
                    "date": "",
                    "year": null,
                    "month": null,
                    "isCurrent": false
                  },
                  "durationInMonths": null
                },
                "educationMajor": [],
                "educationLevel": {
                  "id": null,
                  "label": "",
                  "value": ""
                }
                }
                ],
                "workExperience": [
                {
                "workExperienceJobTitle": "",
                "workExperienceOrganization": "",
                "workExperienceDates": {
                  "end": {
                    "day": null,
                    "date": "",
                    "year": null,
                    "month": null,
                    "isCurrent": true
                  },
                  "start": {
                    "day": null,
                    "date": "",
                    "year": null,
                    "month": null,
                    "isCurrent": false
                  },
                  "durationInMonths": null
                },
                "workExperienceDescription": "",
                "highlights": {
                "minItems": 3,
                "maxItems": 7,
                "items":  [{
                    "bullet": "",
                    "impact": "",
                    "keywords": "",
                    "confidence": ""
                  },
                  ],
                },
                "workExperienceType": {
                  "id": null,
                  "label": "",
                  "value": ""
                }
                }
                ],
                "totalYearsExperience": null,
                "project": null,
                "achievement": [],
                "rightToWork": null,
                "languages": [
                {
                "name": "",
                "level": null
                }
                ],
                "skill": [
                {
                "name": "",
                "type": "Specialized Skill"
                }
                ]
                }
                }
                
                Rules:
                - Respond ONLY with JSON — no extra commentary.
                - Leave fields as `null` if the value is unknown or not found.
                
                PROMPT;


            $gptResponse = Http::timeout(180)->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model, // Dynamic model selection
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $Systemprompt
                    ],
                    [
                        'role' => 'user',
                        'content' => "Raw Text : {$cleanOutput}"
                    ]
                ],
                'temperature' => 0.0, // Minimize randomness
                'response_format' => ['type' => 'json_object'], // Ensure JSON output
                'max_tokens' => 5000, // Allow for detailed evaluation
            ]);

        
            $evaluation = $gptResponse->json()['choices'][0]['message']['content'] ?? null;
            $parsedData = json_decode($evaluation, true);

            if (isset($evaluation)) {
                $aiText = $evaluation;

                // Extract only the JSON part
                $jsonStart = strpos($aiText, '{');
                if ($jsonStart !== false) {
                    $jsonString = substr($aiText, $jsonStart);
                    $decoded = json_decode($jsonString, true);

                    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data'])) {
                        $decoded['data']['languageStyle'] = $style_adjective;

                        return response()->json($decoded);
                    }

                    return response()->json([
                        'error' => 'Invalid JSON from GPT-4o',
                        'raw' => $aiText,
                    ], 500);
                }

                return response()->json([
                    'error' => 'No JSON found in GPT-4o response',
                    'raw' => $aiText,
                ], 500);
            }

            return response()->json([
                'error' => 'Failed to get evaluation from AI model',
            ], 500);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get evaluation from AI model', 'details' => $e->getMessage()], 500);
        }
    }
     




    public function parseResumeGPT(Request $request)
    {
            $request->validate([
                'file' => 'required|file|mimes:pdf|max:204800', 
            ]);
            
            $model = $request->input('model', 'gpt-4o-mini'); 
            $file = $request->file('file');
            
            // Create training data directories
            $trainingBase = public_path('training_data');
            $pdfDir = $trainingBase . '/resume-pdfs';
            $jsonDir = $trainingBase . '/resume-jsons';
            $ocrDir = $trainingBase . '/ocr-outputs';
            
            // Ensure directories exist
            if (!file_exists($pdfDir)) mkdir($pdfDir, 0755, true);
            if (!file_exists($jsonDir)) mkdir($jsonDir, 0755, true);
            if (!file_exists($ocrDir)) mkdir($ocrDir, 0755, true);
            
            try {
                // Generate unique filename with timestamp
                $timestamp = time();
                $uniqueId = uniqid();
                $baseFilename = "resume_{$timestamp}_{$uniqueId}";
                
                // Store original PDF
                $pdfFilename = $baseFilename . '.pdf';
                $pdfPath = $pdfDir . '/' . $pdfFilename;
                $file->move($pdfDir, $pdfFilename);
                
                $text = '';
                $usedOcr = false;
                $ocrPath = null;
                
                // 1. First try direct text extraction
                $parser = new Parser();
                $pdf = $parser->parseFile($pdfPath);
                $text = trim($pdf->getText());
                
                // 2. Fallback to OCR if text extraction fails
                if (strlen($text) < 100) {
                    $usedOcr = true;
                    
                    // Use Python script for OCR
                    $pythonPath = '/var/www/html/backend_cv_finder/env/bin/python';
                    $scriptPath = public_path('scripts/parse_resume.py');
                    $command = sprintf(
                        '%s %s "%s"',
                        escapeshellarg($pythonPath),
                        escapeshellarg($scriptPath),
                        str_replace('"', '\"', $pdfPath)
                    );
                    
                    $output = shell_exec($command . ' 2>&1');
                    $cleanOutput = mb_convert_encoding(trim($output), 'UTF-8', 'UTF-8');
                    
                    // Save OCR output to file
                    $ocrFilename = $baseFilename . '_ocr.txt';
                    $ocrPath = $ocrDir . '/' . $ocrFilename;
                    file_put_contents($ocrPath, $cleanOutput);
                    
                    $text = $cleanOutput;
                }
                
                // Process with GPT regardless of OCR or direct extraction
                $apiKey = config('services.openai.api_key');
                
                $prompt = <<<PROMPT
                You are a resume parsing AI. Analyze the candidate's CV and extract structured information in the following JSON format. Fill as many fields as possible based on the text.
                
                ### RAW TEXT:
                "{$text}"
                
                ### REQUIRED JSON FORMAT:
                {
                "data": {
                    "candidateName": [
                    {
                        "firstName": "",
                        "familyName": ""
                    }
                    ],
                    "headline": "",
                    "website": null,
                    "preferredWorkLocation": null,
                    "willingToRelocate": null,
                    "objective": null,
                    "association": null,
                    "hobby": null,
                    "patent": null,
                    "publication": null,
                    "referee": null,
                    "dateOfBirth": null,
                    "headshot": null,
                    "nationality": null,
                    "email": [""],
                    "phoneNumber": [
                    {
                        "rawText": "",
                        "countryCode": "",
                        "nationalNumber": "",
                        "formattedNumber": "",
                        "internationalCountryCode": ""
                    }
                    ],
                    "location": {
                    "city": "",
                    "state": "",
                    "poBox": null,
                    "street": null,
                    "country": "",
                    "latitude": null,
                    "formatted": "",
                    "longitude": null,
                    "rawInput": "",
                    "stateCode": "",
                    "postalCode": null,
                    "countryCode": "",
                    "streetNumber": null,
                    "apartmentNumber": null
                    },
                    "availability": null,
                    "summary": "",
                    "expectedSalary": null,
                    "education": [
                    {
                        "educationAccreditation": "",
                        "educationOrganization": "",
                        "educationDates": {
                        "end": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "start": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "durationInMonths": null
                        },
                        "educationMajor": [],
                        "educationLevel": {
                        "id": null,
                        "label": "",
                        "value": ""
                        }
                    }
                    ],
                    "workExperience": [
                    {
                        "workExperienceJobTitle": "",
                        "workExperienceOrganization": "",
                        "workExperienceDates": {
                        "end": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": true
                        },
                        "start": {
                            "day": null,
                            "date": "",
                            "year": null,
                            "month": null,
                            "isCurrent": false
                        },
                        "durationInMonths": null
                        },
                        "workExperienceDescription": "",
                        "workExperienceType": {
                        "id": null,
                        "label": "",
                        "value": ""
                        }
                    }
                    ],
                    "totalYearsExperience": null,
                    "project": null,
                    "achievement": null,
                    "rightToWork": null,
                    "languages": [
                    {
                        "name": "",
                        "level": null
                    }
                    ],
                    "skill": [
                    {
                        "name": "",
                        "type": "Specialized Skill"
                    }
                    ]
                }
                }
                
                Rules:
                - Respond ONLY with JSON — no extra commentary.
                - Leave fields as `null` if the value is unknown or not found.
                - Ensure `rawText` contains the same original content provided.
                PROMPT;
                
                $gptResponse = Http::timeout(60)->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a professional JSON resume parser. Respond ONLY with valid full JSON.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.0,
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 2048,
                ]);

                $evaluation = $gptResponse->json()['choices'][0]['message']['content'] ?? null;
                $parsedData = json_decode($evaluation, true);

                if ($evaluation) {
                    $aiText = $evaluation;
                    
                    // Extract only the JSON part
                    $jsonStart = strpos($aiText, '{');
                    if ($jsonStart !== false) {
                        $jsonString = substr($aiText, $jsonStart);
                        $decoded = json_decode($jsonString, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data'])) {
                            $parsedData = $decoded;
                        }
                    }
                }

                // Save JSON to training data
                $jsonFilename = $baseFilename . '.json';
                $jsonPath = $jsonDir . '/' . $jsonFilename;
                file_put_contents($jsonPath, json_encode($parsedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                // Save to database
                $pdfParsed = new PdfParsed();
                $pdfParsed->ip_address = $request->ip();
                $pdfParsed->user_agent = $request->userAgent();
                if (isset($parsedData['data']['candidateName'][0]['firstName'], $parsedData['data']['candidateName'][0]['familyName'])) {
                    $pdfParsed->full_name = $parsedData['data']['candidateName'][0]['firstName'] . ' ' . $parsedData['data']['candidateName'][0]['familyName'];
                }
                $pdfParsed->file_name = $file->getClientOriginalName();
                $pdfParsed->parsed_data = $parsedData;
                $pdfParsed->training_file_id = $baseFilename; // Store the unique ID for reference
                $pdfParsed->used_ocr = $usedOcr;
                $pdfParsed->save();

                return response()->json([
                    'success' => true,
                    'data' => $parsedData,
                    'training_data' => [
                        'file_id' => $baseFilename,
                        'pdf_path' => asset(str_replace(public_path(), '', $pdfPath)),
                        'json_path' => asset(str_replace(public_path(), '', $jsonPath)),
                        'ocr_used' => $usedOcr,
                        'ocr_path' => $usedOcr ? asset(str_replace(public_path(), '', $ocrPath)) : null,
                        'text_length' => strlen($text)
                    ]
                ]);

            } catch (\Exception $e) {
                \Log::error('Resume parsing failed: ' . $e->getMessage(), [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process resume: ' . $e->getMessage()
                ], 500);
            }
    }



    public function downloadDoc($id, Request $request)
    {
        try {
            // 1. Get resume data (similar to your PDF download method)
            $resume = Resume::findOrFail($id);

            if(!$resume){
                return response()->json([
                    'success' => false,
                    'message' => 'Resume not found'
                ], 404);
            }

            // 2. Get and decode resume data
            $resumeData = $resume->cv_resumejson;
            
            if (empty($resumeData)) {
                \Log::error('Resume data is empty', ['resume_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is empty'
                ], 400);
            }
            
            if (is_string($resumeData)) {
                $decoded = json_decode($resumeData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Log::error('Failed to decode resume JSON', [
                        'resume_id' => $id,
                        'error' => json_last_error_msg()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid resume data format: ' . json_last_error_msg()
                    ], 400);
                }
                $resumeData = $decoded;
            }

            // 3. Create simple HTML content (avoid complex HTML structure)
            $html = $this->generateResumeHTML($resumeData);

            // 4. Create DOCX with proper settings
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Set document properties
            $phpWord->getDocInfo()->setCreator($resumeData['candidateName'][0]['firstName'] . ' ' . $resumeData['candidateName'][0]['familyName']);
            $phpWord->getDocInfo()->setTitle('Resume - ' . $resumeData['candidateName'][0]['firstName'] . ' ' . $resumeData['candidateName'][0]['familyName']);
            
            $section = $phpWord->addSection();
            
            // Add HTML content - use simple HTML without DOCTYPE, html, head, body tags
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html, false, false);

            $title = $resume->title;

            // 5. Create a temporary file
            $fileName = 'resume_' . $resumeData['candidateName'][0]['firstName'] . '_' . $resumeData['candidateName'][0]['familyName'] . '.docx';
            $tempPath = $title . '.docx';

            // 6. Save the document
            $phpWord->save($tempPath, 'Word2007', true);

            // 7. Send the file
            return response()->download($tempPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            \Log::error('DOCX Generation Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate DOCX: ' . $e->getMessage()
            ], 500);
        }
    }


    // Helper function to generate simple HTML for the resume
    private function generateResumeHTML($resumeData)
    {
        $html = '';
        
        // Header with name and photo area
        $html .= '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px;">';
        
        // Name and headline section
        $html .= '<div>';
        $html .= '<div style="color: #000; font-size: 24px; font-weight: bold; margin-bottom: 5px; font-family: Inter, Times New Roman, serif;">' . 
                htmlspecialchars(($resumeData['candidateName'][0]['firstName'] ?? '') . ' ' . ($resumeData['candidateName'][0]['familyName'] ?? '')) . 
                '</div>';
        if (!empty($resumeData['headline'])) {
            $html .= '<div style="font-style: italic; margin: 0 0 15px 0; color: #555; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['headline']) . 
                    '</div>';
        }
        $html .= '</div>';
        
        // Profile photo area (space reserved but no image in Word)
        if (!empty($resumeData['profilePic'])) {
            $html .= '<div style="width: 80px; height: 80px; border-radius: 50%; border: 2px solid #ddd; background-color: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px;">';
            $html .= 'Photo';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '<br></br>';
        // Personal Details Table
        if (empty($resumeData['personalDisabled'])) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['personalTitle'] ?? 'Personal details') . 
                    '</div>';
            
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 25px; font-family: Inter, Times New Roman, serif;">';
            
            // Name
            $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Name</td>';
            $html .= '<td style="padding: 3px 0; vertical-align: top;">' . 
                    htmlspecialchars(($resumeData['candidateName'][0]['firstName'] ?? '') . ' ' . ($resumeData['candidateName'][0]['familyName'] ?? '')) . 
                    '</td></tr>';
            
            // Email
            if (!empty($resumeData['email'][0])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Email address</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['email'][0]) . '</td></tr>';
            }
            
            // Phone
            if (!empty($resumeData['phoneNumber'][0]['formattedNumber'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Phone number</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['phoneNumber'][0]['formattedNumber']) . '</td></tr>';
            }
            
            // Address
            if (!empty($resumeData['location']['formatted'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Address</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['formatted']) . '</td></tr>';
            }
            
            // City
            if (!empty($resumeData['location']['city'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">City</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['city']) . '</td></tr>';
            }
            
            // Postcode
            if (!empty($resumeData['location']['postCode'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Postcode</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['location']['postCode']) . '</td></tr>';
            }
            
            // GitHub
            if (!empty($resumeData['socialLinks']['github'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">GitHub</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['github']) . '</td></tr>';
            }
            
            // LinkedIn
            if (!empty($resumeData['socialLinks']['linkedin'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">LinkedIn</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['linkedin']) . '</td></tr>';
            }
            
            // Website
            if (!empty($resumeData['socialLinks']['website'])) {
                $html .= '<tr><td style="padding: 3px 0; vertical-align: top; width: 30%; font-weight: bold; color: #000;">Website</td>';
                $html .= '<td style="padding: 3px 0; vertical-align: top;">' . htmlspecialchars($resumeData['socialLinks']['website']) . '</td></tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
            $html .= '<br></br>';
        // Profile Summary
        if (!empty($resumeData['summary']['paragraph'])) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">Profile</div>';
            $html .= '<div style="margin: 0 0 25px 0; text-align: justify; line-height: 1.5; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['summary']['paragraph']) . 
                    '</div>';
            $html .= '</div>';
        }

        $html .= '<br></br>';
        
        // Work Experience
        if (!empty($resumeData['workExperience']) && !($resumeData['employmentDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['employmentTitle'] ?? 'Employment') . 
                    '</div>';
            
            foreach ($resumeData['workExperience'] as $job) {
                $html .= '<div style="margin-bottom: 20px; page-break-inside: avoid;">';
                
                // Date
                $html .= '<div style="font-weight: 500; margin: 0 0 4px 0; font-size: 14px; color: #000; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars(($job['workExperienceDates']['start']['date'] ?? '') . ' - ' . ($job['workExperienceDates']['end']['date'] ?? 'Present')) . 
                        '</div>';
                
                // Job Title
                $html .= '<div style="font-weight: 600; margin: 0 0 4px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($job['workExperienceJobTitle'] ?? '') . 
                        '</div>';
                
                // Company
                $html .= '<div style="font-weight: 600; margin: 0 0 8px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($job['workExperienceOrganization'] ?? '') . 
                        '</div>';
                
                // Job Description
                if (!empty($job['workExperienceDescription'])) {
                    $html .= '<div style="margin: 0 0 8px 0; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($job['workExperienceDescription']) . 
                            '</div>';
                }
                
                // Key Achievements
                if (!empty($job['highlights']['items'])) {
                    $html .= '<div style="font-weight: 600; margin: 8px 0 5px; color: #000; font-family: Inter, Times New Roman, serif;">Key Achievements</div>';
                    $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
                    foreach ($job['highlights']['items'] as $point) {
                        $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($point['bullet']) . '</li>';
                    }
                    $html .= '</ul>';
                }
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '<br></br>';

        // Education
        if (!empty($resumeData['education']) && !($resumeData['educationDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['educationTitle'] ?? 'Education') . 
                    '</div>';
            
            foreach ($resumeData['education'] as $edu) {
                $html .= '<div style="margin-bottom: 20px; page-break-inside: avoid;">';
                
                // Date
                $html .= '<div style="font-weight: 500; margin: 0 0 4px 0; font-size: 14px; color: #000; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars(($edu['educationDates']['start']['date'] ?? '') . ' - ' . ($edu['educationDates']['end']['date'] ?? '')) . 
                        '</div>';
                
                // Degree
                $html .= '<div style="font-weight: 600; margin: 0 0 4px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($edu['educationLevel']['label'] ?? '') . 
                        '</div>';
                
                // Institution
                $html .= '<div style="font-weight: 600; margin: 0 0 8px 0; color: #000; font-size: 14px; font-family: Inter, Times New Roman, serif;">' . 
                        htmlspecialchars($edu['educationOrganization'] ?? '') . 
                        '</div>';
                
                // Subjects/Major
                if (!empty($edu['educationMajor']) && is_array($edu['educationMajor']) && count($edu['educationMajor']) > 0) {
                    $html .= '<div style="margin: 5px 0; font-family: Inter, Times New Roman, serif;"><strong>Subjects:</strong> ' . 
                            htmlspecialchars(implode(', ', $edu['educationMajor'])) . 
                            '</div>';
                }
                
                // Grade
                if (!empty($edu['achievedGrade'])) {
                    $html .= '<div style="margin: 5px 0; font-family: Inter, Times New Roman, serif;"><strong>Grade:</strong> ' . 
                            htmlspecialchars($edu['achievedGrade']) . 
                            '</div>';
                }
                
                // Education Description
                if (!empty($edu['educationDescription'])) {
                    $html .= '<div style="margin: 8px 0 0 0; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($edu['educationDescription']) . 
                            '</div>';
                }
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '<br></br>';
        
        // Skills
        if (!empty($resumeData['skill']) && !($resumeData['skillsDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['skillsTitle'] ?? 'Skills') . 
                    '</div>';
            
            $skills = array_filter($resumeData['skill'], function($skill) {
                if (is_array($skill)) {
                    return !isset($skill['selected']) || $skill['selected'];
                }
                return true;
            });
            
            $skillNames = array_map(function($skill) {
                return is_array($skill) ? ($skill['name'] ?? $skill) : $skill;
            }, $skills);
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($skillNames as $skill) {
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($skill) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Languages
        if (!empty($resumeData['languages']) && !($resumeData['languagesDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['languagesTitle'] ?? 'Languages') . 
                    '</div>';
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($resumeData['languages'] as $lang) {
                $level = $lang['level'] ?? 'Fluent';
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($lang['name']) . ' (' . htmlspecialchars($level) . ')</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Hobbies
        if (!empty($resumeData['hobbies']) && !($resumeData['hobbiesDisabled'] ?? false)) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                    htmlspecialchars($resumeData['hobbiesTitle'] ?? 'Hobbies') . 
                    '</div>';
            
            $html .= '<ul style="padding-left: 18px; margin: 0 0 20px 0; font-family: Inter, Times New Roman, serif;">';
            foreach ($resumeData['hobbies'] as $hobby) {
                $html .= '<li style="margin-bottom: 4px;">' . htmlspecialchars($hobby) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        $html .= '<br></br>';

        // Custom Sections
        if (!empty($resumeData['customSections'])) {
            foreach ($resumeData['customSections'] as $section) {
                if (!empty($section['title']) && !empty($section['content'])) {
                    $html .= '<div style="margin-bottom: 30px;">';
                    $html .= '<div style="font-size: 16px; font-weight: bold; margin: 0 0 12px 0; color: #000; padding-bottom: 3px; border-bottom: 1px solid #ddd; text-transform: uppercase; font-family: Inter, Times New Roman, serif;">' . 
                            htmlspecialchars($section['title']) . 
                            '</div>';
                    
                    // Clean HTML content while preserving basic formatting
                    $content = strip_tags($section['content'], '<p><br><strong><em><u><ul><ol><li>');
                    $content = str_replace('<p>', '<p style="margin: 8px 0; font-family: Inter, Times New Roman, serif;">', $content);
                    $content = str_replace('<ul>', '<ul style="padding-left: 18px; margin: 8px 0; font-family: Inter, Times New Roman, serif;">', $content);
                    $content = str_replace('<li>', '<li style="margin-bottom: 4px;">', $content);
                    
                    $html .= '<div style="font-family: Inter, Times New Roman, serif;">' . $content . '</div>';
                    $html .= '</div>';
                }
            }
        }

        return $html;
    }

    public function download($id, Request $request)
    {
        try {
            // 1. Get resume record from DB
            $resume = Resume::findOrFail($id);

            if(!$resume){
                return response()->json([
                    'success' => false,
                    'message' => 'Resume not found'
                ], 404);
            }
            
            // 2. Validate template parameter
            $template = $request->input('template');
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template parameter is required'
                ], 400);
            }
            $template = strtolower($template);
            
            // Validate template exists
            $validTemplates = ['classic', 'default', 'luxe', 'modern'];
            if (!in_array($template, $validTemplates)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid template. Valid templates: ' . implode(', ', $validTemplates)
                ], 400);
            }

            // 3. Get and decode resume data
            $resumeData = $resume->cv_resumejson;
            
            // Check if cv_resumejson is null or empty
            if (empty($resumeData)) {
                \Log::error('Resume data is empty', ['resume_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is empty'
                ], 400);
            }
            
            // If cv_resumejson is a JSON string, decode it
            if (is_string($resumeData)) {
                $decoded = json_decode($resumeData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Log::error('Failed to decode resume JSON', [
                        'resume_id' => $id,
                        'error' => json_last_error_msg()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid resume data format: ' . json_last_error_msg()
                    ], 400);
                }
                $resumeData = $decoded;
            }
            
            // Log resume data structure for debugging
            \Log::info('Resume data structure', [
                'resume_id' => $id,
                'data_keys' => is_array($resumeData) ? array_keys($resumeData) : 'not an array',
                'data_type' => gettype($resumeData)
            ]);
            
            // Validate that resumeData is an array
            if (!is_array($resumeData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resume data is not in the correct format'
                ], 400);
            }

            // 4. Pass it to Blade template
            $pdf = Pdf::loadView(
                'resume::pdfs.' . $template . '-template',
                compact('resumeData')
            )->setPaper('a4', 'portrait');

            // 5. Return as inline PDF with proper headers
            $filename = ($resume->title) . '.pdf';
            
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
            
        } catch (\Exception $e) {
            \Log::error('Error generating PDF', [
                'resume_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating PDF: ' . $e->getMessage()
            ], 500);
        }
    }

}
