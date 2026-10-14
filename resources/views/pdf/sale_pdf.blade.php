<!DOCTYPE html>
<html lang="en">
   <head>
      <meta charset="utf-8">
      <title>Sale _{{$sale['Ref']}}</title>
      <link rel="stylesheet" href="{{ public_path('css/pdf_style.css') }}" media="all" />
   </head>

   <body>
      <header class="clearfix">
         <div id="logo">
         <img src="{{ public_path('images/'.$setting['logo']) }}">
         </div>
         <div id="company">
            <div><strong> Date: </strong>{{$sale['date']}}</div>
            <div><strong> Number: </strong> {{$sale['Ref']}}</div>
            <div><strong> Status: </strong> {{$sale['statut']}}</div>
            <div><strong> Payment Status: </strong> {{$sale['payment_status']}}</div>
         </div>
         <div id="Title-heading">
            Sale  {{$sale['Ref']}}
         </div>
         </div>
      </header>
      <main>
         <div id="details" class="clearfix">
            <div id="client">
               <table class="table-sm">
                  <thead>
                     <tr>
                        <th class="desc">Customer Info</th>
                     </tr>
                  </thead>
                  <tbody>
                     <tr>
                        <td>
                           <div><strong>Name:</strong> {{$sale['client_name']}}</div>
                           <div><strong>Phone:</strong> {{$sale['client_phone']}}</div>
                           <div><strong>Adress:</strong>   {{$sale['client_adr']}}</div>
                           <div><strong>Email:</strong>  {{$sale['client_email']}}</div>
                        </td>
                     </tr>
                  </tbody>
               </table>
            </div>
            <div id="invoice">
               <table class="table-sm">
                  <thead>
                     <tr>
                        <th class="desc">Company Info</th>
                     </tr>
                  </thead>
                  <tbody>
                     <tr>
                        <td>
                           <div id="comp">{{$setting['CompanyName']}}</div>
                           <div><strong>Adress:</strong>  {{$setting['CompanyAdress']}}</div>
                           <div><strong>Phone:</strong>  {{$setting['CompanyPhone']}}</div>
                           <div><strong>Email:</strong>  {{$setting['email']}}</div>
                        </td>
                     </tr>
                  </tbody>
               </table>
            </div>
         </div>
         <div id="details_inv">
            <table  class="table-sm">
               <thead>
                  <tr>
                     <th>PRODUCT</th>
                     <th>UNIT PRICE</th>
                     <th>QUANTITY</th>
                     <th>DISCOUNT</th>
                     <th>TAX</th>
                     <th>TOTAL</th>
                  </tr>
               </thead>
               <tbody>
                  @foreach ($details as $detail)
                  <tr>
                     <td>{{$detail['code']}} ({{$detail['name']}})</td>
                     <td>{{$detail['price']}} </td>
                     <td>{{$detail['quantity']}}/{{$detail['unitSale']}}</td>
                     <td>{{$detail['DiscountNet']}} </td>
                     <td>{{$detail['taxe']}} </td>
                     <td>{{$detail['total']}} </td>
                  </tr>
                  @endforeach
               </tbody>
            </table>
         </div>
         <div id="total">
            <table>
               <tr>
                  <td>Order Tax</td>
                  <td>{{$sale['TaxNet']}} </td>
               </tr>
               <tr>
                  <td>Discount</td>
                  <td>{{$sale['discount']}} </td>
               </tr>
               <tr>
                  <td>Shipping</td>
                  <td>{{$sale['shipping']}} </td>
               </tr>
               @if(!empty($sale['statutory_label']))
               <tr>
                  <td>{{$sale['statutory_label']}} Discount (20%)</td>
                  <td>- {{$symbol}} {{$sale['statutory_discount']}} </td>
               </tr>
               @endif
               <tr>
                  <td>Total</td>
                  <td>{{$symbol}} {{$sale['GrandTotal']}} </td>
               </tr>
            </table>
         </div>

         <!-- The VAT breakdown a registered invoice has to carry. A 0% rate and
              a VAT-exempt sale are the same number and different filings, so the
              two are reported on separate lines. -->
         <div id="vat-summary">
            <table>
               <tr>
                  <td>VATable Sales</td>
                  <td>{{$symbol}} {{$sale['vatable_sales']}}</td>
               </tr>
               <tr>
                  <td>{{$sale['vat_rate']}}% VAT</td>
                  <td>{{$symbol}} {{$sale['vat_amount']}}</td>
               </tr>
               <tr>
                  <td>VAT-Exempt Sales</td>
                  <td>{{$symbol}} {{$sale['vat_exempt_sales']}}</td>
               </tr>
               <tr>
                  <td>Zero-Rated Sales</td>
                  <td>{{$symbol}} {{$sale['zero_rated_sales']}}</td>
               </tr>
            </table>
         </div>

         @if(!empty($sale['statutory_label']))
         <!-- The law requires the beneficiary's name, ID number and signature on
              the face of the invoice, not merely in the day's records. -->
         <div id="statutory">
            <table>
               <tr>
                  <td>{{$sale['statutory_label']}} Name</td>
                  <td>{{$sale['statutory_holder_name']}}</td>
               </tr>
               <tr>
                  <td>{{$sale['statutory_label']}} ID No.</td>
                  <td>{{$sale['statutory_id_number']}}</td>
               </tr>
               @if($sale['statutory_total_persons'] > 1)
               <tr>
                  <td>Beneficiaries</td>
                  <td>{{$sale['statutory_beneficiaries']}} of {{$sale['statutory_total_persons']}} persons</td>
               </tr>
               @endif
               <tr>
                  <td>Signature</td>
                  <td>_______________________</td>
               </tr>
            </table>
         </div>
         @endif
         <div id="signature">Signature</div>
      </main>
   </body>
</html>
