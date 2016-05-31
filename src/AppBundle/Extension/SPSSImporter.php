<?php
namespace AppBundle\Extension;

use AppBundle\Entity\User;
use AppBundle\Entity\Dataset;
use AppBundle\Entity\Question;
use AppBundle\Entity\Answer;
use AppBundle\Entity\UserAnswer;

class SPSSImporter
{
    protected $doctrine;

    // Data attributes
    protected $dimensionIds = array(
        'A1' => 'region---',
        'A1.Alt' => 'region',
        'A2' => 'urbanity',
        'B1' => 'gender',
        'Β2' => 'age',
        'Β3' => 'profession',
        'Β4' => 'educationLevel',
        'Β.5' => 'income---',
        'Β.5.Alt' => 'income',
        '17' => 'politicalView---',
        '17.Alt' => 'politicalView',
        '17.Alt_2' => 'politicalView---',
        '18' => 'politicalView---', // DIFFERENT
        '19' => 'socialClass---',
        '19.Alt' => 'socialClass',
    );
    protected $questions;
    protected $dimensions;

    protected $allQuestions;
    protected $allAnswers;

    public function __construct($doctrine) {
        $this->doctrine = $doctrine;
    }

    public function import(\SPSSReader $SPSS, $filename) {
        // Create the dataset
        $dataset = $this->doctrine->getRepository('AppBundle\Entity\Dataset')->findOneBy(array(
            'filename' => $filename,
        ));
        if(!isset($dataset)) {
            $dataset = new Dataset();
            $dataset->setFilename($filename);
            $this->doctrine->getManager()->persist($dataset);
            $this->doctrine->getManager()->flush($dataset);
        }

        // Import questions
        $this->importQuestions($SPSS, $dataset);

        // Import answers
        $this->importAnswers($SPSS, $dataset);
    }

    private function isDimension($questionId) {
        if(in_array($questionId, array_keys($this->dimensionIds))) {
            return true;
        } else {
            return false;
        }
    }

    private function importQuestions(\SPSSReader $SPSS, Dataset $dataset) {
        $toFlush = array();
        foreach($SPSS->variables as $var) {
            if($var->isExtended) { continue; }
            $index = isset($SPSS->extendedNames[$var->shortName]) ? $SPSS->extendedNames[$var->shortName] : $var->name;

            // -- Split question id --
            $questionSplitted = explode(' ', mb_convert_encoding($var->label, 'UTF-8', 'ISO-8859-7'), 2);
            $questionSplitted[0] = rtrim($questionSplitted[0], '.');
            $tmpSplit = explode('.', $questionSplitted[0], 2);
            if($tmpSplit[0] == '3') { $tmpSplit[0] = '9'; } // C
            else if($tmpSplit[0] == '4') { $tmpSplit[0] = '8'; } // C
            else if($tmpSplit[0] == '5') { $tmpSplit[0] = '10'; } // C
            else if($tmpSplit[0] == '6') { $tmpSplit[0] = '12'; } // C
            else if($tmpSplit[0] == '7') { $tmpSplit[0] = '13'; } // C
            else if($tmpSplit[0] == '8') { $tmpSplit[0] = '19'; } // PROBLEM
            else if($tmpSplit[0] == '9') { $tmpSplit[0] = '23'; } // C
            else if($tmpSplit[0] == '10') { $tmpSplit[0] = '24'; } // C
            else if($tmpSplit[0] == '11') { $tmpSplit[0] = '25'; } // C
            else if($tmpSplit[0] == '12') { $tmpSplit[0] = '36'; } // C
            else if($tmpSplit[0] == '13') { $tmpSplit[0] = '37'; } // C
            else if($tmpSplit[0] == '14') { $tmpSplit[0] = '38'; } // C
            else if($tmpSplit[0] == '15') { $tmpSplit[0] = '53'; } // C
            else if($tmpSplit[0] == '16') { $tmpSplit[0] = '56'; } // C
            $questionSplitted[0] = implode('.', $tmpSplit);
            // -----------------------
            $allQuestions = $this->doctrine->getRepository('AppBundle\Entity\Question')->findAll();
            foreach($allQuestions as $curQuestion) { $this->allQuestions[$curQuestion->getQuestionId()] = $curQuestion; }
            $allAnswers = $this->doctrine->getRepository('AppBundle\Entity\Answer')->findAll();
            foreach($allAnswers as $curAnswer) { $this->allAnswers[$curAnswer->getQuestion()->getQuestionId().'_'.$curAnswer->getAnswerId()] = $curAnswer; }
            if(!isset($this->allQuestions[$questionSplitted[0]])) {
                $question = new Question();
                $question->setQuestionId($questionSplitted[0]);
                $question->setQuestion($questionSplitted[1]);
                $question->setDataset($dataset);
                $this->allQuestions[$questionSplitted[0]] = $question;
            } else {
                $question = $this->allQuestions[$questionSplitted[0]];
                $question->getAnswers()->clear();
            }
            if(!$this->isDimension($question->getQuestionId())) {
                $this->doctrine->getManager()->persist($question);
                $toFlush[] = $question;
                $this->questions[$index] = $question;
            } else {
                $this->dimensions[$index] = $question;
            }
            foreach($var->valueLabels as $lkey => $lval) {
                if(!isset($this->allAnswers[$questionSplitted[0].'_'.$lkey])) {
                    $answer = new Answer();
                    $answer->setAnswerId($lkey);
                    $this->allAnswers[$questionSplitted[0].'_'.$lkey] = $answer;
                } else {
                    $answer = $this->allAnswers[$questionSplitted[0].'_'.$lkey];
                }
                $answer->setAnswer(mb_convert_encoding($lval, 'UTF-8', 'ISO-8859-7'));
                $answer->setQuestion($question);
                $question->getAnswers()->add($answer);
                if(!$this->isDimension($question->getQuestionId())) {
                    $this->doctrine->getManager()->persist($answer);
                    $toFlush[] = $answer;
                }
            }
        }
        if(count($toFlush) > 0) {
            $this->doctrine->getManager()->flush($toFlush);
        }
    }

    private function importAnswers(\SPSSReader $SPSS, Dataset $dataset) {
        // Loop through the answers
        $SPSS->loadData();
        for($case=0; $case<$SPSS->header->numberOfCases; $case++) {
            $user = new User();
            $user->setSessionId(microtime());
            $user->setAutoGenerated(true);
            $toFlush = array($user);
            foreach($SPSS->variables as $var) {
                if ($var->isExtended) { continue; }
                $index = isset($SPSS->extendedNames[$var->shortName]) ? $SPSS->extendedNames[$var->shortName] : $var->name;

                if(isset($this->dimensions[$index])) { // This is a dimension attribute
                    // Check if the dimension is a valid profile dimension
                    if($this->isValidProfileDimension($this->dimensions[$index]->getQuestionId())) {
                        // Set the profile dimension
                        $dimension = $this->dimensionIds[$this->dimensions[$index]->getQuestionId()];
                        $setter = 'set'.ucfirst($dimension);
                        $user->$setter($this->mapProfileDimensionValue($dimension, $var->data[$case]==='NaN'?'':$var->data[$case]));
                    }
                } else if($this->questions[$index]) {
                    // Create a UserAnswer
                    $userAnswer = new UserAnswer();
                    $userAnswer->setDataset($dataset);
                    $userAnswer->setUser($user);
                    if($var->data[$case]==='NaN' || $var->data[$case]=='') { continue; }
                    if(!isset($this->allAnswers[$this->questions[$index]->getQuestionId().'_'.$var->data[$case]])) {
                        continue;
                        //throw new \Exception('Could not find user answer for '.$index.' ('.$var->data[$case].') given');
                    }
                    $answer = $this->allAnswers[$this->questions[$index]->getQuestionId().'_'.$var->data[$case]];
                    $userAnswer->setAnswer($answer);
                    $this->doctrine->getManager()->persist($userAnswer);
                    $toFlush[] = $userAnswer;
                } else {
                    throw new \Exception('Answer to a non-existing question! '.$index);
                }
            }
            $this->doctrine->getManager()->persist($user);
            $this->doctrine->getManager()->flush($toFlush);
            foreach($toFlush as $curEntity) {
                $this->doctrine->getManager()->detach($curEntity); // To save memory
            }
        }
    }

    private function isValidProfileDimension($dimensionId) {
        $dimension = $this->dimensionIds[$dimensionId];
        $profileDimensions = User::getDimensionsExpanded();
        if(in_array($dimension, array_keys($profileDimensions))) {
            return true;
        } else {
            return false;
        }
    }

    private function mapProfileDimensionValue($dimension, $origValue) {
        $map = array(
            'gender' => array(
                1 => User::GENDER_MALE,
                2 => User::GENDER_FEMALE,
            ),
            'age' => array(
                1 => User::AGE_18_24,
                2 => User::AGE_25_39,
                3 => User::AGE_40_54,
                4 => User::AGE_55_64,
                5 => User::AGE_65P,
            ),
            'educationLevel' => array(
                1 => User::EDUCATION_PRIMARY,
                2 => User::EDUCATION_SECONDARY,
                3 => User::EDUCATION_TERTIARY,
                4 => User::EDUCATION_MASTER,
                99 => User::EDUCATION_UNKNOWN,
            ),
            'income' => array(
                1 => User::INCOME_500M,
                3 => User::INCOME_501_1000,
                4 => User::INCOME_1001_1500,
                5 => User::INCOME_1501_2000,
                6 => User::INCOME_2001_3000,
                7 => User::INCOME_3001P,
                99 => User::INCOME_UNKNOWN,
            ),
            'profession' => array(
                1 => User::PROFESSION_CIVIL_SERVANT,
                2 => User::PROFESSION_PRIVATE_EMPLOYEE,
                3 => User::PROFESSION_FREELANCER_SCIENTIST,
                4 => User::PROFESSION_FREELANCER_NON_SCIENTIST,
                5 => User::PROFESSION_ENTREPRENEUR,
                6 => User::PROFESSION_FARMER,
                7 => User::PROFESSION_STUDENT,
                8 => User::PROFESSION_HOUSEWIFE,
                9 => User::PROFESSION_RETIRED,
                10 => User::PROFESSION_UNEMPLOYED,
                99 => User::PROFESSION_UNKNOWN,
            ),
            'socialClass' => array(
                1 => User::SOCIAL_CLASS_LOWER,
                3 => User::SOCIAL_CLASS_MIDDLE,
                4 => User::SOCIAL_CLASS_UPPER,
                99 => User::SOCIAL_CLASS_UNKNOWN,
            ),
            'region' => array(
                1 => User::REGION_ATTICA,
                2 => User::REGION_THESSALONIKI,
                3 => User::REGION_CENTRAL_GREECE,
                4 => User::REGION_NORTH_AEGEAN,
                5 => User::REGION_CRETE,
            ),
            'urbanity' => array(
                1 => User::URBANITY_URBAN,
                2 => User::URBANITY_RURAL,
            ),
            'politicalView' => array(
                1 => User::POLITICAL_VIEW_LEFT,
                3 => User::POLITICAL_VIEW_CENTER_LEFT,
                4 => User::POLITICAL_VIEW_CENTER,
                5 => User::POLITICAL_VIEW_CENTER_RIGHT,
                6 => User::POLITICAL_VIEW_RIGHT,
                99 => User::POLITICAL_VIEW_UNKNOWN,
            ),
        );
        return $map[$dimension][$origValue];
    }
}