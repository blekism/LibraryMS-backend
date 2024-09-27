<?php

require '../inc/dbcon.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use WebSocket\Client;



function verifyAccount($userInput)
{
    global $con;

    if (isset($userInput['verification_code'])) {
        $verifyCode = mysqli_real_escape_string($con, $userInput['verification_code']);
        $query = "UPDATE library_members_tbl SET verified_at = NOW() WHERE verification_code = '$verifyCode' ";
        $result = mysqli_query($con, $query);

        if ($result) {
            $data = [
                'status' => 200,
                'message' => 'Account Verified',
            ];
            header("HTTP/1.0 200 Verified");
            return json_encode($data);
        }
    } else {
        return error422('Enter Verification Code');
    }
}



function error422($message)
{
    $data = [
        'status' => 422,
        'message' => $message,
    ];
    header("HTTP/1.0 422 Unprocessable Entity");
    echo json_encode($data);
    exit();
}

function phpMailer($userInput)
{
    global $con;

    if (isset($userInput['email']) && isset($userInput['password'])) {
        $email = mysqli_real_escape_string($con, $userInput['email']);
        $password = mysqli_real_escape_string($con, $userInput['password']);
        $last_name = mysqli_real_escape_string($con, $userInput['last_name']);
        $first_name = mysqli_real_escape_string($con, $userInput['first_name']);
        $mail = new PHPMailer(true);

        try {
            //Server settings
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;                      //Enable verbose debug output
            $mail->isSMTP();                                            //Send using SMTP
            $mail->Host       = 'smtp.gmail.com';                     //Set the SMTP server to send through
            $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
            $mail->Username   = '';                     //SMTP username
            $mail->Password   = '';                               //SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            //Enable implicit TLS encryption
            $mail->Port       = 465;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            //Recipients
            $mail->setFrom('', 'Mailer');
            $mail->addAddress($email);
            $verificationCode = substr(number_format(time() * rand(), 0, '', ''), 0, 6);     //Add a recipient


            //Content
            $mail->isHTML(true);                                  //Set email format to HTML
            $mail->Subject = 'Verification code';
            $mail->Body    = 'Your verification code is: ' . $verificationCode;
            $mail->AltBody = 'Your verification code is: ' . $verificationCode;

            $mail->send();
            echo 'Message has been sent';

            $query = "INSERT INTO 
            library_members_tbl(
                first_name, 
                last_name, 
                email, 
                password, 
                verification_code) 
            VALUES (
                '$first_name',
                '$last_name',
                '$email', 
                '$password', 
                '$verificationCode')";
            $result = mysqli_query($con, $query);

            if ($result) {
                $data = [
                    'status' => 201,
                    'message' => 'Email Added',
                ];
                header("HTTP/1.0 201 Inserted");
                return json_encode($data);
            } else {
                $data = [
                    'status' => 500,
                    'message' => 'Internal Server Error',
                ];
                header("HTTP/1.0 500 Internal Server Error");
                return json_encode($data);
            }
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        return error422('Enter Email and Password');
    }
}

function loop($loopInput)
{
    global $con;


    mysqli_begin_transaction($con);

    try {
        $insertedCount = 0;
        $insertedID = [];
        foreach ($loopInput as $author) {
            $author_FN = mysqli_real_escape_string($con, $author['first_name']);
            $author_LN = mysqli_real_escape_string($con, $author['last_name']);

            if (empty(trim($author_FN))) {
                return error422('Enter First Name');
            } elseif (empty(trim($author_LN))) {
                return error422('Enter Last Name');
            } else {
                $query = "INSERT INTO 
                    authors_tbl(first_name, last_name) 
                    VALUES ('$author_FN', '$author_LN')";
                $result = mysqli_query($con, $query);

                if ($result) {
                    $insertedID[] = mysqli_insert_id($con);
                    $insertedCount++;
                } else {
                    throw new Exception('Failed to insert author');
                }
            }
        }

        foreach ($insertedID as $test) {
            $query1 = "INSERT INTO test(testid) VALUES ('$test')";
            $res1 = mysqli_query($con, $query1);

            if (!$res1) {
                throw new Exception('Failed to insert test');
            }
        }

        mysqli_commit($con);

        if ($insertedCount > 0) {
            $data = [
                'status' => 201,
                'message_1' => "$insertedCount authors added successfully",
                'message_2' => "test inserted successfully",
                'inserted_ids' => $insertedID
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 400,
                'message' => 'No authors were added',
            ];
            header("HTTP/1.0 400 Bad Request");
            return json_encode($data);
        }
    } catch (Exception $e) {

        mysqli_rollback($con);
        $data = [
            'status' => 500,
            'message' => $e->getMessage(),
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}

function sendWebSocketMessage($message)
{
    try {
        $client = new Client("ws://localhost:8080");
        $client->send($message);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

function userLogin($userParams)
{
    global $con;


    $email = mysqli_real_escape_string($con, $userParams['email']);
    $password = mysqli_real_escape_string($con, $userParams['password']);

    if (empty(trim($email))) {
        return error422('Enter Email');
    } elseif (empty(trim($password))) {
        return error422('Enter Password');
    }

    $query = "SELECT * FROM library_members_tbl WHERE email = '$email' AND password = '$password' LIMIT 1";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $row = mysqli_fetch_assoc($result);

            $accountStatus = mysqli_real_escape_string($con, $row['verified_at']);
            if ($accountStatus == null) {
                $data = [
                    'status' => 403,
                    'message' => 'Account not verified',
                ];
                header("HTTP/1.0 403 Forbidden");
                return json_encode($data);
            } else {
                $data = [
                    'status' => 200,
                    'message' => 'Login successful',
                    'data' => $row,
                ];
            }


            header("HTTP/1.0 200 Login Successful");
            return json_encode($data);
        } else {
            return error422('Invalid email or password');
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}




//------------------- GET BOOK START ------------------------//
function getBookList()
{

    global $con;

    $query = "SELECT * FROM books_tbl";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            $res = mysqli_fetch_all($result, MYSQLI_ASSOC);
            $data = [
                'status' => 200,
                'message' => 'Books Found',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Books Found");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Books Found',
            ];
            header("HTTP/1.0 404 No Books Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- GET BOOK END ------------------------//


//------------------- ADD BOOK START ------------------------//
function insertBook($bookInput)
{

    global $con;

    $author_id = mysqli_real_escape_string($con, $bookInput['author_id']);
    $publisher_id = mysqli_real_escape_string($con, $bookInput['publisher_id']);
    $title = mysqli_real_escape_string($con, $bookInput['title']);
    $genre = mysqli_real_escape_string($con, $bookInput['genre']);
    $publication_year = mysqli_real_escape_string($con, $bookInput['publication_year']);
    $num_copies = mysqli_real_escape_string($con, $bookInput['num_of_copies']);
    $shelf_location = mysqli_real_escape_string($con, $bookInput['shelf_location']);

    if (empty(trim($author_id))) {
        return error422('Enter Author ID');
    } elseif (empty(trim($publisher_id))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($title))) {
        return error422('Enter Title');
    } elseif (empty(trim($genre))) {
        return error422('Enter Genre');
    } elseif (empty(trim($publication_year))) {
        return error422('Enter Publication Year');
    } elseif (empty(trim($num_copies))) {
        return error422('Enter Number of Copies');
    } elseif (empty(trim($shelf_location))) {
        return error422('Enter Shelf Location');
    } else {
        $query = "INSERT INTO 
            books_tbl(author_id, publisher_id, title, genre, publication_year, num_of_copies, shelf_location) 
            VALUES ('$author_id', '$publisher_id', '$title', '$genre', '$publication_year', '$num_copies', '$shelf_location')";
        $result = mysqli_query($con, $query);

        if ($result) {

            sendWebSocketMessage(json_encode([
                'author_id' => $author_id,
                'publisher_id' => $publisher_id,
                'title' => $title,
                'genre' => $genre,
                'publication_year' => $publication_year,
                'num_of_copies' => $num_copies,
                'shelf_location' => $shelf_location,
            ]));

            $data = [
                'status' => 201,
                'message' => 'Book Added',
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- ADD BOOK END ------------------------//

//------------------- UPDATE BOOK START ------------------------//
function updateBook($bookInput, $bookParams)
{
    global $con;

    if (!isset($bookParams['book_id'])) {
        return error422('Book ID not found in url');
    } elseif ($bookParams['book_id'] == null) {
        return error422('Enter the Book ID');
    }

    $book_id = mysqli_real_escape_string($con, $bookParams['book_id']);

    $author_id = mysqli_real_escape_string($con, $bookInput['author_id']);
    $publisher_id = mysqli_real_escape_string($con, $bookInput['publisher_id']);
    $title = mysqli_real_escape_string($con, $bookInput['title']);
    $genre = mysqli_real_escape_string($con, $bookInput['genre']);
    $publication_year = mysqli_real_escape_string($con, $bookInput['publication_year']);
    $num_copies = mysqli_real_escape_string($con, $bookInput['num_of_copies']);
    $shelf_location = mysqli_real_escape_string($con, $bookInput['shelf_location']);

    if (empty(trim($author_id))) {
        return error422('Enter Author ID');
    } elseif (empty(trim($publisher_id))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($title))) {
        return error422('Enter Title');
    } elseif (empty(trim($genre))) {
        return error422('Enter Genre');
    } elseif (empty(trim($publication_year))) {
        return error422('Enter Publication Year');
    } elseif (empty(trim($num_copies))) {
        return error422('Enter Number of Copies');
    } elseif (empty(trim($shelf_location))) {
        return error422('Enter Shelf Location');
    } else {
        $query = "UPDATE books_tbl SET 
        author_id = '$author_id', 
        publisher_id = '$publisher_id', 
        title = '$title', 
        genre = '$genre', 
        publication_year = '$publication_year', 
        num_of_copies = '$num_copies', 
        shelf_location = '$shelf_location' WHERE book_id = '$book_id' ";

        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 200,
                'message' => 'Book Updated Sucessfully ',
            ];
            header("HTTP/1.0 200 Updated");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- UPDATE BOOK END ------------------------//

//------------------- READ SINGLE BOOK START ------------------------//
function getBook($bookParams)
{
    global $con;

    if ($bookParams['book_id'] == null) {
        return error422('Enter Book ID');
    }

    $book_id = mysqli_real_escape_string($con, $bookParams['book_id']);

    $query = "SELECT * FROM books_tbl WHERE book_id = '$book_id' LIMIT 1 ";

    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $res = mysqli_fetch_assoc($result);

            $data = [
                'status' => 200,
                'message' => 'Book Fetched Successfully ',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Fetched");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Book Found',
            ];
            header("HTTP/1.0 404 Not Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- READ SINGLE BOOK END ------------------------//

//------------------- DELETE SINGLE BOOK START ------------------------//
function deleteBook($bookParams)
{

    global $con;

    if (!isset($bookParams['book_id'])) {
        return error422('Book ID not found in url');
    } elseif ($bookParams['book_id'] == null) {
        return error422('Enter the Book ID');
    }

    $book_id = mysqli_real_escape_string($con, $bookParams['book_id']);

    $query = "DELETE FROM books_tbl WHERE book_id = '$book_id' LIMIT 1";
    $result = mysqli_query($con, $query);

    if ($result) {

        $data = [
            'status' => 200,
            'message' => 'Book Deleted Successfully',
        ];
        header("HTTP/1.0 200 Success");
        return json_encode($data);
    } else {
        $data = [
            'status' => 404,
            'message' => 'Book not found',
        ];
        header("HTTP/1.0 404 Not Found");
        return json_encode($data);
    }
}
//------------------- DELETE SINGLE BOOK END ------------------------//

//------------------- ADD AUTHOR START ------------------------//
function insertAuthor($authorInput)
{
    global $con;

    $author_FN = mysqli_real_escape_string($con, $authorInput['first_name']);
    $author_LN = mysqli_real_escape_string($con, $authorInput['last_name']);


    if (empty(trim($author_FN))) {
        return error422('Enter First Name');
    } elseif (empty(trim($author_LN))) {
        return error422('Enter Last Name');
    } else {
        $query = "INSERT INTO 
            authors_tbl(first_name, last_name) 
            VALUES ('$author_FN', '$author_LN')";
        $result = mysqli_query($con, $query);
        $author_id = mysqli_insert_id($con);

        if ($result) {

            sendWebSocketMessage(json_encode([

                'first_name' => $author_FN,
                'last_name' => $author_LN,
                'author_id' => $author_id,

            ]));

            $data = [
                'status' => 201,
                'message' => 'Book Added',
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}

//------------------- ADD AUTHOR END ------------------------//

//------------------- ADD BORROW START ------------------------//
function insertBorrow($borrowInput)
{
    global $con;

    $book_id = mysqli_real_escape_string($con, $borrowInput['book_id']);
    $member_id = mysqli_real_escape_string($con, $borrowInput['member_id']);
    $borrow_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+30 days'));
    $status = mysqli_real_escape_string($con, $borrowInput['status']);

    if (empty(trim($book_id))) {
        return error422('Enter Book ID');
    } elseif (empty(trim($member_id))) {
        return error422('Enter Member ID');
    } elseif (empty(trim($borrow_date))) {
        return error422('Enter borrow date');
    } elseif (empty(trim($due_date))) {
        return error422('Enter due date');
    } elseif (empty(trim($status))) {
        return error422('Enter status');
    } else {
        $query = "INSERT INTO 
            booksborrowed_tbl(book_id, member_id, borrow_date, due_date, status) 
            VALUES ('$book_id', '$member_id', '$borrow_date', '$due_date', '$status')";
        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 201,
                'message' => 'Book Added',
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}

//------------------- ADD BORROW END ------------------------//


//------------------- ADD MEMBER START ------------------------//
function insertMember($memberInput)
{
    global $con;

    $mem_FN = mysqli_real_escape_string($con, $memberInput['first_name']);
    $mem_LN = mysqli_real_escape_string($con, $memberInput['last_name']);
    $email = mysqli_real_escape_string($con, $memberInput['email']);
    $contact = mysqli_real_escape_string($con, $memberInput['contact_number']);
    $dob = mysqli_real_escape_string($con, $memberInput['dob']);
    $address = mysqli_real_escape_string($con, $memberInput['address']);
    $mem_start = date('Y-m-d');
    $mem_end = date('Y-m-d', strtotime('+30 days'));

    if (empty(trim($mem_FN))) {
        return error422('Enter Book ID');
    } elseif (empty(trim($mem_LN))) {
        return error422('Enter Member ID');
    } elseif (empty(trim($email))) {
        return error422('Enter borrow date');
    } elseif (empty(trim($contact))) {
        return error422('Enter due date');
    } elseif (empty(trim($dob))) {
        return error422('Enter return date');
    } elseif (empty(trim($address))) {
        return error422('Enter status');
    } else {
        $query = "INSERT INTO 
            library_members_tbl(first_name, last_name, email, contact_number, dob, address, membership_start, membership_end) 
            VALUES ('$mem_FN', '$mem_LN', '$email', '$contact', '$dob', '$address', '$mem_start', '$mem_end')";
        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 201,
                'message' => 'Book Added',
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}

//------------------- ADD MEMBER END ------------------------//

//------------------- ADD PUBLISHER START ------------------------//
function insertPublisher($publisherInput)
{
    global $con;

    $publisher_name = mysqli_real_escape_string($con, $publisherInput['publisher_name']);
    $address = mysqli_real_escape_string($con, $publisherInput['address']);
    $contact_number = mysqli_real_escape_string($con, $publisherInput['contact_number']);
    $email = mysqli_real_escape_string($con, $publisherInput['email']);
    $website = mysqli_real_escape_string($con, $publisherInput['website']);


    if (empty(trim($publisher_name))) {
        return error422('Enter Book ID');
    } elseif (empty(trim($address))) {
        return error422('Enter Member ID');
    } elseif (empty(trim($contact_number))) {
        return error422('Enter borrow date');
    } elseif (empty(trim($email))) {
        return error422('Enter due date');
    } elseif (empty(trim($website))) {
        return error422('Enter return date');
    } else {
        $query = "INSERT INTO 
            publisher_tbl(publisher_name, address, contact_number, email, website) 
            VALUES ('$publisher_name', '$address', '$contact_number', '$email', '$website')";
        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 201,
                'message' => 'Book Added',
            ];
            header("HTTP/1.0 201 Inserted");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}

//------------------- ADD PUBLISHER END ------------------------//


//------------------- DELETE MEMBER START ------------------------//
function deleteMember($deleteParams)
{
    global $con;

    if (!isset($deleteParams['member_id'])) {
        return error422('Member ID not found in url');
    } elseif ($deleteParams['member_id'] == null) {
        return error422('Enter the Member ID');
    }

    $member_id = mysqli_real_escape_string($con, $deleteParams['member_id']);

    $query = "DELETE FROM library_members_tbl WHERE member_id = '$member_id' LIMIT 1";
    $result = mysqli_query($con, $query);

    if ($result) {

        $data = [
            'status' => 200,
            'message' => 'Book Deleted Successfully',
        ];
        header("HTTP/1.0 200 Success");
        return json_encode($data);
    } else {
        $data = [
            'status' => 404,
            'message' => 'Book not found',
        ];
        header("HTTP/1.0 404 Not Found");
        return json_encode($data);
    }
}
//------------------- DELETE MEMBER END ------------------------//

//------------------- DELETE PUBLISHER START ------------------------//
function deletePublisher($deleteParams)
{
    global $con;

    if (!isset($deleteParams['publisher_id'])) {
        return error422('Publisher ID not found in url');
    } elseif ($deleteParams['publisher_id'] == null) {
        return error422('Enter the Publisher ID');
    }

    $publisher_id = mysqli_real_escape_string($con, $deleteParams['publisher_id']);

    $query = "DELETE FROM publisher_tbl WHERE publisher_id = '$publisher_id' LIMIT 1";
    $result = mysqli_query($con, $query);

    if ($result) {

        $data = [
            'status' => 200,
            'message' => 'Book Deleted Successfully',
        ];
        header("HTTP/1.0 200 Success");
        return json_encode($data);
    } else {
        $data = [
            'status' => 404,
            'message' => 'Book not found',
        ];
        header("HTTP/1.0 404 Not Found");
        return json_encode($data);
    }
}
//------------------- DELETE PUBLISHER END ------------------------//

//------------------- DELETE AUTHORS START ------------------------//
function deleteAuthor($deleteParams)
{
    global $con;

    if (!isset($deleteParams['author_id'])) {
        return error422('Member ID not found in url');
    } elseif ($deleteParams['author_id'] == null) {
        return error422('Enter the Author ID');
    }

    $author_id = mysqli_real_escape_string($con, $deleteParams['author_id']);

    $query = "DELETE FROM authors_tbl WHERE member_id = '$author_id' LIMIT 1";
    $result = mysqli_query($con, $query);

    if ($result) {

        $data = [
            'status' => 200,
            'message' => 'Book Deleted Successfully',
        ];
        header("HTTP/1.0 200 Success");
        return json_encode($data);
    } else {
        $data = [
            'status' => 404,
            'message' => 'Book not found',
        ];
        header("HTTP/1.0 404 Not Found");
        return json_encode($data);
    }
}
//------------------- DELETE AUTHORS END ------------------------//

//------------------- GET BORROW START ------------------------//
function borrowList()
{

    global $con;

    $query = "SELECT * FROM booksborrowed_tbl";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            $res = mysqli_fetch_all($result, MYSQLI_ASSOC);

            $data = [
                'status' => 200,
                'message' => 'Borrows Found',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Books Found");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Books Found',
            ];
            header("HTTP/1.0 404 No Books Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- GET BORROW END ------------------------//

//------------------- GET AUTHOR START ------------------------//
function authorList()
{

    global $con;

    $query = "SELECT * FROM authors_tbl";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            $res = mysqli_fetch_all($result, MYSQLI_ASSOC);

            $data = [
                'status' => 200,
                'message' => 'Borrows Found',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Books Found");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Books Found',
            ];
            header("HTTP/1.0 404 No Books Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- GET AUTHOR END ------------------------//

//------------------- GET MEMBER START ------------------------//
function memberList()
{

    global $con;

    $query = "SELECT * FROM library_members_tbl";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            $res = mysqli_fetch_all($result, MYSQLI_ASSOC);

            $data = [
                'status' => 200,
                'message' => 'Borrows Found',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Books Found");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Books Found',
            ];
            header("HTTP/1.0 404 No Books Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- GET MEMBER END ------------------------//

//------------------- GET PUBLISHER START ------------------------//
function publisherList()
{

    global $con;

    $query = "SELECT * FROM publisher_tbl";
    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            $res = mysqli_fetch_all($result, MYSQLI_ASSOC);

            $data = [
                'status' => 200,
                'message' => 'Borrows Found',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Books Found");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Books Found',
            ];
            header("HTTP/1.0 404 No Books Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- GET PUBLISHER END ------------------------//

//------------------- READ SINGLE AUTHOR START ------------------------//
function getAuthor($authParams)
{
    global $con;

    if ($authParams['author_id'] == null) {
        return error422('Enter Book ID');
    }

    $author_id = mysqli_real_escape_string($con, $authParams['author_id']);

    $query = "SELECT * FROM authors_tbl WHERE author_id = '$author_id' LIMIT 1 ";

    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $res = mysqli_fetch_assoc($result);

            $data = [
                'status' => 200,
                'message' => 'Book Fetched Successfully ',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Fetched");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Book Found',
            ];
            header("HTTP/1.0 404 Not Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- READ SINGLE AUTHOR END ------------------------//

//------------------- READ SINGLE BORROW START ------------------------//
function getBorrow($lmsParams)
{
    global $con;

    if ($lmsParams['borrow_id'] == null) {
        return error422('Enter Book ID');
    }

    $borrow_id = mysqli_real_escape_string($con, $lmsParams['borrow_id']);

    $query = "SELECT * FROM booksborrowed_tbl WHERE borrow_id = '$borrow_id' LIMIT 1 ";

    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $res = mysqli_fetch_assoc($result);

            $data = [
                'status' => 200,
                'message' => 'Book Fetched Successfully ',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Fetched");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Book Found',
            ];
            header("HTTP/1.0 404 Not Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- READ SINGLE BORROW END ------------------------//

//------------------- READ SINGLE MEMBER START ------------------------//
function getMember($lmsParams)
{
    global $con;

    if ($lmsParams['member_id'] == null) {
        return error422('Enter Book ID');
    }

    $member_id = mysqli_real_escape_string($con, $lmsParams['member_id']);

    $query = "SELECT * FROM library_members_tbl WHERE member_id = '$member_id' LIMIT 1 ";

    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $res = mysqli_fetch_assoc($result);

            $data = [
                'status' => 200,
                'message' => 'Book Fetched Successfully ',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Fetched");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Book Found',
            ];
            header("HTTP/1.0 404 Not Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- READ SINGLE MEMBER END ------------------------//


//------------------- READ SINGLE PUBLISHER START ------------------------//
function getPublisher($lmsParams)
{
    global $con;

    if ($lmsParams['publisher_id'] == null) {
        return error422('Enter Book ID');
    }

    $publisher_id = mysqli_real_escape_string($con, $lmsParams['publisher_id']);

    $query = "SELECT * FROM publisher_tbl WHERE publisher_id = '$publisher_id' LIMIT 1 ";

    $result = mysqli_query($con, $query);

    if ($result) {
        if (mysqli_num_rows($result) == 1) {
            $res = mysqli_fetch_assoc($result);

            $data = [
                'status' => 200,
                'message' => 'Book Fetched Successfully ',
                'data' => $res,
            ];
            header("HTTP/1.0 200 Fetched");
            return json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'No Book Found',
            ];
            header("HTTP/1.0 404 Not Found");
            return json_encode($data);
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error',
        ];
        header("HTTP/1.0 500 Internal Server Error");
        return json_encode($data);
    }
}
//------------------- READ SINGLE PUBLISHER END ------------------------//

//------------------- UPDATE AUTHOR START ------------------------//
function updateAuthor($lmsInput, $lmsParams)
{
    global $con;

    if (!isset($lmsParams['author_id'])) {
        return error422('Book ID not found in url');
    } elseif ($lmsParams['author_id'] == null) {
        return error422('Enter the Book ID');
    }

    $author_id = mysqli_real_escape_string($con, $lmsParams['author_id']);

    $first_name = mysqli_real_escape_string($con, $lmsInput['first_name']);
    $last_name = mysqli_real_escape_string($con, $lmsInput['last_name']);


    if (empty(trim($first_name))) {
        return error422('Enter Author ID');
    } elseif (empty(trim($last_name))) {
        return error422('Enter Publisher ID');
    } else {
        $query = "UPDATE authors_tbl SET 
        first_name = '$first_name', 
        last_name = '$last_name'
         WHERE author_id = '$author_id' ";

        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 200,
                'message' => 'Book Updated Sucessfully ',
            ];
            header("HTTP/1.0 200 Updated");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- UPDATE AUTHOR END ------------------------//

//------------------- UPDATE BORROW START ------------------------//
function updateBorrow($lmsInput, $lmsParams)
{
    global $con;

    if (!isset($lmsParams['borrow_id'])) {
        return error422('Book ID not found in url');
    } elseif ($lmsParams['borrow_id'] == null) {
        return error422('Enter the Book ID');
    }

    $borrow_id = mysqli_real_escape_string($con, $lmsParams['borrow_id']);

    $book_id = mysqli_real_escape_string($con, $lmsInput['book_id']);
    $member_id = mysqli_real_escape_string($con, $lmsInput['member_id']);
    $borrow_date = mysqli_real_escape_string($con, $lmsInput['borrow_date']);
    $due_date = mysqli_real_escape_string($con, $lmsInput['due_date']);
    $return_date = mysqli_real_escape_string($con, $lmsInput['return_date']);
    $status = mysqli_real_escape_string($con, $lmsInput['status']);


    if (empty(trim($book_id))) {
        return error422('Enter Author ID');
    } elseif (empty(trim($member_id))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($borrow_date))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($due_date))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($return_date))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($status))) {
        return error422('Enter Publisher ID');
    } else {
        $query = "UPDATE booksborrowed_tbl SET 
        book_id = '$book_id', 
        member_id = '$member_id',
        borrow_date = '$borrow_date', 
        due_date = '$due_date',
        return_date = '$return_date', 
        status = '$status'
         WHERE borrow_id = '$borrow_id' ";

        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 200,
                'message' => 'Book Updated Sucessfully ',
            ];
            header("HTTP/1.0 200 Updated");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- UPDATE BORROW END ------------------------//

//------------------- UPDATE BORROW START ------------------------//
function updateMember($lmsInput, $lmsParams)
{
    global $con;

    if (!isset($lmsParams['borrow_id'])) {
        return error422('Book ID not found in url');
    } elseif ($lmsParams['borrow_id'] == null) {
        return error422('Enter the Book ID');
    }

    $member_id = mysqli_real_escape_string($con, $lmsParams['member_id']);

    $first_name = mysqli_real_escape_string($con, $lmsInput['first_name']);
    $last_name = mysqli_real_escape_string($con, $lmsInput['last_name']);
    $email = mysqli_real_escape_string($con, $lmsInput['email']);
    $contact_number = mysqli_real_escape_string($con, $lmsInput['contact_number']);
    $dob = mysqli_real_escape_string($con, $lmsInput['dob']);
    $address = mysqli_real_escape_string($con, $lmsInput['address']);
    $membership_start = mysqli_real_escape_string($con, $lmsInput['membership_start']);
    $membership_end = mysqli_real_escape_string($con, $lmsInput['membership_end']);


    if (empty(trim($first_name))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($last_name))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($email))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($contact_number))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($dob))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($address))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($membership_start))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($membership_end))) {
        return error422('Enter Publisher ID');
    } else {
        $query = "UPDATE library_members_tbl SET 
        first_name = '$first_name', 
        last_name = '$last_name',
        email = '$email', 
        contact_number = '$contact_number',
        dob = '$dob', 
        address = '$address',
        membership_start = '$membership_start',
        membership_end = '$membership_end'
         WHERE member_id = '$member_id' ";

        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 200,
                'message' => 'Book Updated Sucessfully ',
            ];
            header("HTTP/1.0 200 Updated");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- UPDATE BORROW END ------------------------//

//------------------- UPDATE BORROW START ------------------------//
function updatePublisher($lmsInput, $lmsParams)
{
    global $con;

    if (!isset($lmsParams['publisher_id'])) {
        return error422('Book ID not found in url');
    } elseif ($lmsParams['publisher_id'] == null) {
        return error422('Enter the Book ID');
    }

    $publisher_id = mysqli_real_escape_string($con, $lmsParams['publisher_id']);

    $publisher_name = mysqli_real_escape_string($con, $lmsInput['publisher_name']);
    $address = mysqli_real_escape_string($con, $lmsInput['address']);
    $contact_number = mysqli_real_escape_string($con, $lmsInput['contact_number']);
    $email = mysqli_real_escape_string($con, $lmsInput['email']);
    $website = mysqli_real_escape_string($con, $lmsInput['website']);


    if (empty(trim($publisher_name))) {
        return error422('Enter Author ID');
    } elseif (empty(trim($address))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($contact_number))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($email))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($contact_number))) {
        return error422('Enter Publisher ID');
    } elseif (empty(trim($website))) {
        return error422('Enter Publisher ID');
    } else {
        $query = "UPDATE publisher_tbl SET 
        publisher_name = '$publisher_name', 
        address = '$address',
        contact_number = '$contact_number', 
        email = '$email',
        website = '$website'
         WHERE publisher_id = '$publisher_id' ";

        $result = mysqli_query($con, $query);

        if ($result) {

            $data = [
                'status' => 200,
                'message' => 'Book Updated Sucessfully ',
            ];
            header("HTTP/1.0 200 Updated");
            return json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error',
            ];
            header("HTTP/1.0 500 Internal Server Error");
            return json_encode($data);
        }
    }
}
//------------------- UPDATE BORROW END ------------------------//
