<?php

/* Sample General Training Reading test in the teacher's authoring format. Original content. */

$p = fn (string $text, ?string $label = null) => ['label' => $label, 'text' => $text];

return [
    'sections' => [
        [
            'passages' => [
                [
                    'title' => 'Evening courses at Westbrook College',
                    'subtitle' => 'Spring term – enrol now',
                    'paragraphs' => [
                        $p('Conversational Spanish. Tuesdays, 7–9 pm, for ten weeks. Designed for complete beginners who want to get by on holiday. The course ends with a group meal at a local tapas restaurant, included in the fee. £120.', 'A'),
                        $p('Digital Photography. Saturday mornings, 9.30–12.30. Learn to take control of your camera and edit your pictures. Students must bring their own digital camera. To make sure everyone gets individual attention, the class is limited to eight people. £150.', 'B'),
                        $p('Introduction to Bookkeeping. Study online whenever it suits you, with no fixed timetable. Video lessons and weekly quizzes cover the basics of keeping accounts for a small business. On completion you receive a certificate that many local employers recognise. £95.', 'C'),
                        $p('Pottery for Everyone. Thursdays, 6–8.30 pm. Make bowls, cups and tiles on the wheel and by hand. All clay, glazes and tools are provided. The studio is on the ground floor and is fully accessible to wheelchair users. £180.', 'D'),
                        $p('First Aid at Work. A one-day course run on the first Saturday of every month. Successful participants receive a certificate that remains valid for three years. Many employers will pay the fee for their staff – ask your manager. £75.', 'E'),
                    ],
                ],
                [
                    'title' => 'Using your Hollybank Library card',
                    'paragraphs' => [
                        $p('Library cards are free for anyone who lives, works or studies in Hollybank. To join, please bring a document showing your current address, such as a utility bill dated within the last three months.'),
                        $p('Adult members may borrow up to twelve items at a time. Books may be kept for three weeks, while DVDs must be returned after one week. Items can be renewed online, by phone or in person, as long as no other member has reserved them.'),
                        $p("We no longer charge fines on children's books. Adult members pay 20p for each day an item is late, up to a maximum of £5 per item."),
                        $p('If you lose your card, a replacement costs £2.50. Items that are lost or damaged must be paid for at their full replacement cost.'),
                        $p('Public computers can be booked for up to two hours a day. Black-and-white printing costs 10p per page.'),
                    ],
                ],
            ],
            'groups' => [
                ['type' => 'matching', 'data' => [
                    'variant' => 'information',
                    'instructions' => "Look at the five courses, A–E, in 'Evening courses at Westbrook College'. For which course are the following statements true? Choose the correct letter, A–E. You may use any letter more than once.",
                    'options_title' => 'Courses',
                    'allow_reuse' => true,
                    'options' => [
                        ['text' => 'Conversational Spanish'], ['text' => 'Digital Photography'], ['text' => 'Introduction to Bookkeeping'],
                        ['text' => 'Pottery for Everyone'], ['text' => 'First Aid at Work'],
                    ],
                    'questions' => [
                        ['prompt' => 'This course takes place online.', 'answer' => 'C'],
                        ['prompt' => 'The number of students on this course is limited.', 'answer' => 'B'],
                        ['prompt' => 'This course includes a social outing.', 'answer' => 'A'],
                        ['prompt' => 'Students need to bring their own equipment.', 'answer' => 'B'],
                        ['prompt' => 'The qualification from this course must be renewed after a fixed period.', 'answer' => 'E'],
                        ['prompt' => 'This course is suitable for people with mobility difficulties.', 'answer' => 'D'],
                        ['prompt' => 'Students can work through this course at their own speed.', 'answer' => 'C'],
                    ],
                ]],
                ['type' => 'true_false_not_given', 'data' => [
                    'variant' => 'tfng',
                    'instructions' => "Do the following statements agree with the information in 'Using your Hollybank Library card'? Choose TRUE if the statement agrees with the information, FALSE if the statement contradicts the information, or NOT GIVEN if there is no information on this.",
                    'questions' => [
                        ['statement' => 'You must live in Hollybank to get a library card.', 'answer' => 'false'],
                        ['statement' => 'A bank statement is accepted as proof of address.', 'answer' => 'not given'],
                        ['statement' => 'DVDs can be borrowed for a shorter time than books.', 'answer' => 'true'],
                        ['statement' => 'Items that another member has reserved cannot be renewed.', 'answer' => 'true'],
                        ['statement' => 'Children are fined for returning books late.', 'answer' => 'false'],
                        ['statement' => 'The library replaces lost cards free of charge.', 'answer' => 'false'],
                        ['statement' => 'Colour printing is available in the library.', 'answer' => 'not given'],
                    ],
                ]],
            ],
        ],
        [
            'passages' => [
                [
                    'title' => 'Starting at Calder Logistics: your first week',
                    'paragraphs' => [
                        $p("Welcome to Calder Logistics. During your first week you will be supported by a 'buddy', an experienced colleague from your own team who can answer everyday questions about how things work."),
                        $p('On your first morning, please report to the main reception at 8.30 am, where you will be given a temporary ID badge. Your permanent badge, which includes your photo, will be ready by Wednesday.'),
                        $p('All new staff must complete a health and safety induction before entering the warehouse. The induction lasts about 90 minutes and finishes with a short quiz.'),
                        $p('Safety boots are provided free of charge. High-visibility jackets must be worn at all times in loading areas and can be collected from the stores department.'),
                        $p('Lunch breaks are 45 minutes long. The staff canteen serves hot meals at reduced prices, and there is a quiet room for anyone who prefers to eat away from the noise.'),
                        $p('At the end of your first week you will have a review meeting with your line manager to discuss how you are getting on and any training you might need.'),
                    ],
                ],
                [
                    'title' => 'Requesting flexible working',
                    'paragraphs' => [
                        $p('Any employee who has worked for the company for at least 26 weeks may request flexible working. This could include changes to the number of hours worked, start and finish times, or working from home on some days.'),
                        $p('Requests must be made in writing using form FW1, which is available on the staff intranet. Explain clearly what change you are asking for and how you think it might affect your team.'),
                        $p('Your manager will arrange a meeting with you within 28 days. You may bring a colleague or a trade union representative to this meeting.'),
                        $p('The company will tell you its decision in writing. If your request is refused, you can appeal within 14 days by writing to the Human Resources manager.'),
                        $p('Please note that only one request can be made in any twelve-month period.'),
                    ],
                ],
            ],
            'groups' => [
                ['type' => 'gap_fill', 'data' => [
                    'instructions' => 'Complete the notes below. Choose NO MORE THAN TWO WORDS from the text for each answer.',
                    'word_limit' => 'NO MORE THAN TWO WORDS',
                    'layout' => 'notes',
                    'title' => 'First week at Calder Logistics',
                    'text' => implode("\n", [
                        '- A [[buddy]] helps new staff with everyday questions',
                        '- First day: collect a temporary [[ID badge|badge]] at reception',
                        "- The permanent badge shows the employee's [[photo]]",
                        '- The health and safety induction ends with a [[quiz|short quiz]]',
                        '- [[Safety boots|boots]] are given to staff for free',
                        '- Jackets are available from the [[stores department|stores]]',
                        '- Staff who want to avoid noise at lunch can use the [[quiet room]]',
                    ]),
                ]],
                ['type' => 'short_answer', 'data' => [
                    'instructions' => 'Answer the questions below. Choose NO MORE THAN THREE WORDS AND/OR A NUMBER from the text for each answer.',
                    'word_limit' => 'NO MORE THAN THREE WORDS AND/OR A NUMBER',
                    'questions' => [
                        ['prompt' => 'How long must staff have worked for the company before making a request?', 'answer' => '26 weeks|twenty-six weeks'],
                        ['prompt' => 'Which form must employees complete?', 'answer' => 'FW1|form FW1'],
                        ['prompt' => 'Where can employees find the form?', 'answer' => 'staff intranet|on the intranet|the staff intranet|intranet|on the staff intranet'],
                        ['prompt' => 'Apart from a colleague, who may attend the meeting with the employee?', 'answer' => 'trade union representative|a trade union representative|union representative'],
                        ['prompt' => 'Who should an appeal be sent to?', 'answer' => 'Human Resources manager|the Human Resources manager|HR manager'],
                        ['prompt' => 'How often can an employee make a request?', 'answer' => 'once a year|every twelve months|every 12 months|once every year|once per year'],
                    ],
                ]],
            ],
        ],
        [
            'passages' => [
                [
                    'title' => 'The return of the night train',
                    'subtitle' => "Why sleeper services are back on Europe's railways",
                    'paragraphs' => [
                        $p('For much of the twentieth century, sleeper trains were a familiar part of European travel. Families heading south for the summer and business people travelling between capitals would board in the evening and wake up at their destination. But from the 1990s, as low-cost airlines expanded and high-speed lines cut daytime journey times, route after route was closed. Operators argued that the trains were expensive to run and attracted too few passengers.', 'A'),
                        $p("In recent years, however, the night train has made a surprising comeback. Austria's national railway began expanding its overnight network in 2016, taking over routes that other companies had abandoned. Since then, new services have linked cities such as Vienna, Brussels, Paris and Berlin, and several private companies have entered the market.", 'B'),
                        $p('Concern about climate change is the reason given most often for this revival. A journey by train produces a fraction of the carbon emissions of the same trip by plane, and growing numbers of travellers, particularly younger ones, say they are willing to spend longer on the move in order to reduce their environmental impact.', 'C'),
                        $p('Yet passengers are not motivated by the environment alone. Many describe the experience itself as part of the appeal: falling asleep in one country and waking up in another, without the queues and security checks of an airport. For business travellers, an overnight train can also save the cost of a hotel room, since they arrive in the city centre in time for a morning meeting.', 'D'),
                        $p('Running a night train is far from simple, though. Each service has to cross several national rail systems, each with its own rules, charges and technical standards. Carriages stand idle for most of the day, and staff must be paid for working unsocial hours. As a result, tickets are often more expensive than a budget flight, and some operators have struggled to make a profit.', 'E'),
                        $p('Governments are beginning to respond. Some have reduced the charges that overnight services pay to use the tracks, while the European Union has funded trial projects on new routes. Supporters argue that if the true environmental cost of flying were reflected in ticket prices, night trains would compete far more easily.', 'F'),
                        $p('Whether the revival lasts will depend largely on investment in new carriages, which are expensive and slow to build. For now, demand on many routes is greater than the number of seats available, and popular services sell out weeks in advance – a problem that, for the railway companies, is a welcome change.', 'G'),
                    ],
                ],
            ],
            'groups' => [
                ['type' => 'matching', 'data' => [
                    'variant' => 'headings',
                    'instructions' => 'The text has seven paragraphs, A–G. Choose the correct heading for paragraphs B–G from the list of headings. Example: Paragraph A – i.',
                    'allow_reuse' => false,
                    'options' => [
                        ['text' => 'A decline in popularity'], ['text' => 'An unexpected return'], ['text' => 'The environmental argument'],
                        ['text' => 'More than a greener way to travel'], ['text' => 'The practical difficulties of overnight services'],
                        ['text' => 'Help from the authorities'], ['text' => 'A future limited by equipment'],
                        ['text' => 'Safety concerns among passengers'], ['text' => 'Competition between private companies'],
                    ],
                    'questions' => [
                        ['prompt' => 'Paragraph B', 'answer' => 'ii'],
                        ['prompt' => 'Paragraph C', 'answer' => 'iii'],
                        ['prompt' => 'Paragraph D', 'answer' => 'iv'],
                        ['prompt' => 'Paragraph E', 'answer' => 'v'],
                        ['prompt' => 'Paragraph F', 'answer' => 'vi'],
                        ['prompt' => 'Paragraph G', 'answer' => 'vii'],
                    ],
                ]],
                ['type' => 'multiple_choice', 'data' => [
                    'questions' => [
                        ['prompt' => 'According to paragraph A, why were many night train routes closed?', 'options' => [
                            ['text' => 'Passengers complained about comfort.'], ['text' => 'They cost a lot and were not used enough.', 'correct' => true],
                            ['text' => 'Governments stopped allowing them.'], ['text' => 'The trains were too slow.'],
                        ]],
                        ['prompt' => "What does the writer say about Austria's national railway?", 'options' => [
                            ['text' => 'It was the first company to run night trains.'], ['text' => 'It took over routes from other operators.', 'correct' => true],
                            ['text' => 'It only runs services inside Austria.'], ['text' => 'It has reduced its overnight network.'],
                        ]],
                        ['prompt' => 'Business travellers may choose a night train because', 'options' => [
                            ['text' => 'they can work during the journey.'], ['text' => 'tickets are cheaper than flights.'],
                            ['text' => 'they do not need to pay for a hotel.', 'correct' => true], ['text' => 'trains are more reliable than planes.'],
                        ]],
                        ['prompt' => "What is the writer's main point in paragraph G?", 'options' => [
                            ['text' => 'Night trains will soon replace flights.'], ['text' => 'More carriages are needed to meet demand.', 'correct' => true],
                            ['text' => 'Ticket prices are likely to fall.'], ['text' => 'Railway companies are losing money.'],
                        ]],
                    ],
                ]],
                ['type' => 'gap_fill', 'data' => [
                    'instructions' => 'Complete the summary using the list of words, A–H, below.',
                    'layout' => 'summary',
                    'title' => 'The challenges of night trains',
                    'text' => 'Night trains face several problems. Because they travel through several countries, they must deal with different [[rules]]. Their carriages are not used during the [[day]], and staff costs are high. Consequently, fares are frequently higher than those of a [[budget flight]].',
                    'word_bank' => [
                        ['text' => 'rules'], ['text' => 'passengers'], ['text' => 'day'], ['text' => 'weekend'],
                        ['text' => 'budget flight'], ['text' => 'hotel'], ['text' => 'stations'], ['text' => 'week'],
                    ],
                ]],
            ],
        ],
    ],
];
